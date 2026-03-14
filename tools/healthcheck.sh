#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-https://dtz-lid.com}"
TIMEOUT_SECONDS="${TIMEOUT_SECONDS:-20}"

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

PASS_COUNT=0
FAIL_COUNT=0

strip_trailing_slash() {
  local value="$1"
  echo "${value%/}"
}

BASE_URL="$(strip_trailing_slash "$BASE_URL")"

is_allowed_status() {
  local status="$1"
  local allowed_csv="$2"
  local item
  IFS=',' read -r -a items <<< "$allowed_csv"
  for item in "${items[@]}"; do
    if [[ "$status" == "$item" ]]; then
      return 0
    fi
  done
  return 1
}

print_ok() {
  local msg="$1"
  echo -e "${GREEN}OK${NC}  $msg"
  PASS_COUNT=$((PASS_COUNT + 1))
}

print_fail() {
  local msg="$1"
  echo -e "${RED}FAIL${NC} $msg"
  FAIL_COUNT=$((FAIL_COUNT + 1))
}

print_warn() {
  local msg="$1"
  echo -e "${YELLOW}WARN${NC} $msg"
}

run_check() {
  local label="$1"
  local method="$2"
  local path="$3"
  local allowed_statuses="$4"
  local expected_type="$5"
  local payload="${6:-}"

  local url="${BASE_URL}${path}"
  local response

  if [[ "$method" == "GET" ]]; then
    response="$(
      curl -sS -m "$TIMEOUT_SECONDS" \
        -H "Accept: */*" \
        -w $'\n__STATUS__:%{http_code}\n__CTYPE__:%{content_type}\n' \
        "$url"
    )"
  else
    response="$(
      curl -sS -m "$TIMEOUT_SECONDS" \
        -H "Accept: */*" \
        -H "Content-Type: application/json" \
        -X "$method" \
        --data "$payload" \
        -w $'\n__STATUS__:%{http_code}\n__CTYPE__:%{content_type}\n' \
        "$url"
    )"
  fi

  local status
  status="$(echo "$response" | sed -n 's/^__STATUS__://p' | tail -n 1)"
  local ctype
  ctype="$(echo "$response" | sed -n 's/^__CTYPE__://p' | tail -n 1)"
  local body
  body="$(echo "$response" | sed '/^__STATUS__:/,$d')"

  if [[ -z "$status" ]]; then
    print_fail "$label -> status okunamadı ($url)"
    return
  fi

  if ! is_allowed_status "$status" "$allowed_statuses"; then
    print_fail "$label -> beklenen HTTP [$allowed_statuses], gelen: $status ($url)"
    return
  fi

  if [[ "$expected_type" == "json" ]]; then
    if [[ "$ctype" != application/json* ]]; then
      print_fail "$label -> JSON bekleniyordu, Content-Type: $ctype"
      return
    fi
    if [[ "$body" == *"<!DOCTYPE HTML"* || "$body" == *"<html"* ]]; then
      print_fail "$label -> API HTML döndü (muhtemel 404/route hatası)"
      return
    fi
    if ! [[ "$body" =~ ^[[:space:]]*[\{\[] ]]; then
      print_fail "$label -> JSON bekleniyordu, body JSON ile başlamıyor"
      return
    fi
  fi

  if [[ "$expected_type" == "html" ]]; then
    if [[ "$ctype" != text/html* ]]; then
      print_fail "$label -> HTML bekleniyordu, Content-Type: $ctype"
      return
    fi
    if [[ "$body" != *"<!DOCTYPE"* && "$body" != *"<html"* ]]; then
      print_fail "$label -> body HTML gibi görünmüyor"
      return
    fi
  fi

  print_ok "$label -> HTTP $status ($url)"
}

run_contains_check() {
  local label="$1"
  local path="$2"
  local needle="$3"
  local url="${BASE_URL}${path}"
  local body

  body="$(curl -sS -m "$TIMEOUT_SECONDS" -H "Accept: text/html,*/*" "$url")"
  if [[ "$body" == *"$needle"* ]]; then
    print_ok "$label -> bulundu: $needle ($url)"
  else
    print_fail "$label -> bulunamadı: $needle ($url)"
  fi
}

run_contains_warn() {
  local label="$1"
  local path="$2"
  local needle="$3"
  local url="${BASE_URL}${path}"
  local body

  body="$(curl -sS -m "$TIMEOUT_SECONDS" -H "Accept: text/html,*/*" "$url")"
  if [[ "$body" == *"$needle"* ]]; then
    print_ok "$label -> bulundu: $needle ($url)"
  else
    print_warn "$label -> bulunamadı: $needle ($url)"
  fi
}

echo -e "${YELLOW}DTZ-LID Post-Deploy Healthcheck${NC}"
echo "Base URL: $BASE_URL"
echo

# Public pages
run_check "Startseite" "GET" "/index.html" "200" "html"
run_check "Lehrerbereich" "GET" "/admin.html" "200" "html"

# Session/status endpoints (should always be JSON)
run_check "Admin Session API" "GET" "/api/admin_session.php" "200" "json"
run_check "Student Session API" "GET" "/api/student_session.php" "200" "json"
run_check "DTZ Training API GET (method block)" "GET" "/api/student_training_set.php" "405" "json"
run_check "DTZ Training API POST (anon block)" "POST" "/api/student_training_set.php" "401" "json" "{\"module\":\"hoeren\",\"teil\":1,\"count\":5}"

# Protected endpoints should reject anonymous calls with JSON (not HTML)
run_check "Hausaufgabe speichern (anon blok)" "POST" "/api/homework_assign.php" "401" "json" "{}"
run_check "Mail-Liste laden (anon blok)" "POST" "/api/list_letters.php" "401" "json" "{\"limit\":1}"
run_check "Korrektur API (anon blok)" "POST" "/api/correct.php" "401" "json" "{\"message\":\"test\"}"

# UI smoke: DTZ controls should be present on start page
run_contains_check "DTZ UI: Hören button" "/index.html" "Hören (Teil 1-4)"
run_contains_check "DTZ UI: Lesen button" "/index.html" "Lesen (Teil 1-5)"
run_contains_warn "DTZ UI: Teil select" "/index.html" "dtzTeilSelect"
run_contains_warn "DTZ UI: Hören Exam toggle" "/index.html" "dtzHoerenExamMode"

echo
if [[ "$FAIL_COUNT" -gt 0 ]]; then
  echo -e "${RED}Sonuç: $FAIL_COUNT başarısız, $PASS_COUNT başarılı.${NC}"
  exit 1
fi

echo -e "${GREEN}Sonuç: tüm kontroller başarılı ($PASS_COUNT).${NC}"
