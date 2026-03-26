#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="${BASE_URL:-http://127.0.0.1:8091}"
ADMIN_USER="${ADMIN_USER:-}"
ADMIN_PASS="${ADMIN_PASS:-}"
TEST_STUDENT_USER="${TEST_STUDENT_USER:-}"
DTZ_TEMPLATE_ID="${DTZ_TEMPLATE_ID:-dtz-hoeren-teil1-fragenpaket}"

fail() {
  echo "[ANTI-REPEAT][FAIL] $1" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "missing command: $1"
}

need_cmd curl
need_cmd php

if [[ -z "$ADMIN_USER" || -z "$ADMIN_PASS" || -z "$TEST_STUDENT_USER" ]]; then
  echo "[ANTI-REPEAT] skipped (ADMIN_USER/ADMIN_PASS/TEST_STUDENT_USER missing)"
  exit 0
fi

echo "[ANTI-REPEAT] base_url=$BASE_URL template=$DTZ_TEMPLATE_ID student=$TEST_STUDENT_USER"

COOKIE_FILE="/tmp/dtz_anti_repeat_admin.cookie"
CREATED_IDS=()

cleanup() {
  if [[ ${#CREATED_IDS[@]} -eq 0 ]]; then
    return
  fi
  for aid in "${CREATED_IDS[@]}"; do
    [[ -n "$aid" ]] || continue
    curl -sS -b "$COOKIE_FILE" \
      -X POST "$BASE_URL/api/homework_assign.php" \
      -H "Content-Type: application/json" \
      --data "{\"action\":\"delete\",\"assignment_id\":\"$aid\"}" >/dev/null || true
  done
}
trap cleanup EXIT

cat > /tmp/dtz_anti_repeat_admin_login.json <<JSON
{"username":"${ADMIN_USER}","password":"${ADMIN_PASS}"}
JSON

admin_code="$(curl -sS -c "$COOKIE_FILE" -o /tmp/dtz_anti_repeat_admin_login.out -w "%{http_code}" \
  -X POST "$BASE_URL/api/admin_login.php" \
  -H "Content-Type: application/json" \
  --data-binary @/tmp/dtz_anti_repeat_admin_login.json)"
[[ "$admin_code" == "200" ]] || fail "admin login failed ($admin_code)"
php -r '
$j=json_decode(file_get_contents($argv[1]), true);
if (!is_array($j) || (!(($j["ok"] ?? false) || ($j["authenticated"] ?? false)))) {
  fwrite(STDERR, "admin login payload invalid\n");
  exit(2);
}
' /tmp/dtz_anti_repeat_admin_login.out || fail "admin login response invalid"

create_assignment() {
  local index="$1"
  local payload_file="/tmp/dtz_anti_repeat_create_${index}.json"
  local out_file="/tmp/dtz_anti_repeat_create_${index}.out"
  local now_iso
  now_iso="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
  cat > "$payload_file" <<JSON
{
  "action": "create",
  "template_id": "${DTZ_TEMPLATE_ID}",
  "title": "Anti-Repeat Smoke #${index}",
  "description": "Automated anti-repeat smoke test #${index}",
  "target_type": "users",
  "usernames": ["${TEST_STUDENT_USER}"],
  "duration_minutes": 10,
  "starts_at": "${now_iso}"
}
JSON
  local code
  code="$(curl -sS -b "$COOKIE_FILE" -o "$out_file" -w "%{http_code}" \
    -X POST "$BASE_URL/api/homework_assign.php" \
    -H "Content-Type: application/json" \
    --data-binary @"$payload_file")"
  [[ "$code" == "200" ]] || fail "create #${index} failed ($code): $(cat "$out_file")"
  local assignment_id
  assignment_id="$(php -r '
  $j=json_decode(file_get_contents($argv[1]), true);
  $id=trim((string)($j["assignment_id"] ?? ""));
  if ($id === "") { exit(3); }
  echo $id;
  ' "$out_file")" || fail "create #${index} did not return assignment_id"
  echo "$assignment_id"
}

fetch_report() {
  local out_file="$1"
  local code
  code="$(curl -sS -b "$COOKIE_FILE" -o "$out_file" -w "%{http_code}" \
    -X POST "$BASE_URL/api/homework_assign.php" \
    -H "Content-Type: application/json" \
    --data '{"action":"dtz_usage_report"}')"
  [[ "$code" == "200" ]] || fail "dtz_usage_report failed ($code)"
  php -r '
  $j=json_decode(file_get_contents($argv[1]), true);
  if (!is_array($j) || !($j["ok"] ?? false)) { exit(4); }
  ' "$out_file" || fail "dtz_usage_report payload invalid"
}

extract_ids_for_assignment() {
  local report_file="$1"
  local assignment_id="$2"
  php -r '
  $j=json_decode(file_get_contents($argv[1]), true);
  $aid=(string)$argv[2];
  $set=[];
  foreach (($j["report"]["events_tail"] ?? []) as $row) {
    if (!is_array($row)) { continue; }
    if ((string)($row["assignment_id"] ?? "") !== $aid) { continue; }
    $qid=trim((string)($row["template_id"] ?? ""));
    if ($qid === "") { continue; }
    $set[$qid]=true;
  }
  $ids=array_keys($set);
  sort($ids, SORT_STRING);
  echo implode(",", $ids);
  ' "$report_file" "$assignment_id"
}

set_overlap_count() {
  local ids_a="$1"
  local ids_b="$2"
  php -r '
  $a=array_filter(explode(",", (string)$argv[1]), fn($v) => $v !== "");
  $b=array_filter(explode(",", (string)$argv[2]), fn($v) => $v !== "");
  $sa=array_fill_keys($a, true);
  $n=0;
  foreach ($b as $v) { if (isset($sa[$v])) { $n++; } }
  echo (string)$n;
  ' "$ids_a" "$ids_b"
}

aid1="$(create_assignment 1)"
CREATED_IDS+=("$aid1")
aid2="$(create_assignment 2)"
CREATED_IDS+=("$aid2")

fetch_report /tmp/dtz_anti_repeat_report_12.out
ids1="$(extract_ids_for_assignment /tmp/dtz_anti_repeat_report_12.out "$aid1")"
ids2="$(extract_ids_for_assignment /tmp/dtz_anti_repeat_report_12.out "$aid2")"
[[ -n "$ids1" ]] || fail "assignment #1 has no tracked DTZ question ids"
[[ -n "$ids2" ]] || fail "assignment #2 has no tracked DTZ question ids"

if [[ "$ids1" == "$ids2" ]]; then
  fail "assignment #1 and #2 selected identical DTZ question sets"
fi

echo "[ANTI-REPEAT] set#1 != set#2 confirmed"

del1_code="$(curl -sS -b "$COOKIE_FILE" -o /tmp/dtz_anti_repeat_delete_1.out -w "%{http_code}" \
  -X POST "$BASE_URL/api/homework_assign.php" \
  -H "Content-Type: application/json" \
  --data "{\"action\":\"delete\",\"assignment_id\":\"$aid1\"}")"
[[ "$del1_code" == "200" ]] || fail "delete #1 failed ($del1_code)"
CREATED_IDS=("${CREATED_IDS[@]:1}")

aid3="$(create_assignment 3)"
CREATED_IDS+=("$aid3")
fetch_report /tmp/dtz_anti_repeat_report_123.out
ids3="$(extract_ids_for_assignment /tmp/dtz_anti_repeat_report_123.out "$aid3")"
[[ -n "$ids3" ]] || fail "assignment #3 has no tracked DTZ question ids"

overlap_13="$(set_overlap_count "$ids1" "$ids3")"
if [[ "$overlap_13" != "0" ]]; then
  fail "deleted set #1 overlaps with new set #3 (overlap=$overlap_13)"
fi

echo "[ANTI-REPEAT][OK] deleted-question cooldown verified (overlap with deleted assignment: 0)"
