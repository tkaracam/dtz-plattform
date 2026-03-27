#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="${BASE_URL:-http://127.0.0.1:8091}"

echo "[SMOKE] root=$ROOT_DIR"
echo "[SMOKE] base_url=$BASE_URL"

fail() {
  echo "[SMOKE][FAIL] $1" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "missing command: $1"
}

need_cmd php
need_cmd curl

HAS_RG=0
if command -v rg >/dev/null 2>&1; then
  HAS_RG=1
fi

contains_match() {
  local pattern="$1"
  local file="$2"
  if [[ "$HAS_RG" == "1" ]]; then
    rg -q "$pattern" "$file"
  else
    grep -E -q "$pattern" "$file"
  fi
}

echo "[SMOKE] 1/5 PHP lint"
while IFS= read -r f; do
  php -l "$f" >/dev/null || fail "php lint failed: $f"
done < <(find "$ROOT_DIR/api" -type f -name '*.php' | sort)

echo "[SMOKE] 2/5 unauth endpoint checks"
resp_code="$(curl -sS -o /tmp/dtz_smoke_home.out -w "%{http_code}" "$BASE_URL/")"
[[ "$resp_code" == "200" ]] || fail "GET / returned $resp_code"
contains_match "dtz-build|DTZ|dtz" /tmp/dtz_smoke_home.out || fail "home page marker not found"

portal_code="$(curl -sS -o /tmp/dtz_smoke_portal.out -w "%{http_code}" "$BASE_URL/api/student_portal.php")"
[[ "$portal_code" == "401" || "$portal_code" == "403" ]] || fail "unauth portal expected 401/403, got $portal_code"

echo "[SMOKE] 3/5 admin auth smoke (optional)"
if [[ -n "${ADMIN_USER:-}" && -n "${ADMIN_PASS:-}" ]]; then
  cat > /tmp/dtz_admin_login.json <<JSON
{"username":"${ADMIN_USER}","password":"${ADMIN_PASS}"}
JSON
  admin_code="$(curl -sS -c /tmp/dtz_admin.cookie -o /tmp/dtz_admin_login.out -w "%{http_code}" \
    -X POST "$BASE_URL/api/admin_login.php" \
    -H "Content-Type: application/json" \
    --data-binary @/tmp/dtz_admin_login.json)"
  [[ "$admin_code" == "200" ]] || fail "admin login failed ($admin_code)"
  contains_match '"ok":true|"authenticated":true' /tmp/dtz_admin_login.out || fail "admin login response not authenticated"

  report_code="$(curl -sS -b /tmp/dtz_admin.cookie -o /tmp/dtz_admin_report.out -w "%{http_code}" \
    -X POST "$BASE_URL/api/homework_assign.php" \
    -H "Content-Type: application/json" \
    --data '{"action":"dtz_usage_report"}')"
  [[ "$report_code" == "200" ]] || fail "dtz_usage_report failed ($report_code)"
  contains_match '"ok":true' /tmp/dtz_admin_report.out || fail "dtz_usage_report response invalid"

  progress_code="$(curl -sS -b /tmp/dtz_admin.cookie -o /tmp/dtz_admin_progress.out -w "%{http_code}" \
    "$BASE_URL/api/admin_progress.php?days=7")"
  [[ "$progress_code" == "200" ]] || fail "admin_progress failed ($progress_code)"
  contains_match '"summary"|\"courses\"|\"students\"' /tmp/dtz_admin_progress.out || fail "admin_progress base payload invalid"
  contains_match '"reminders_by_course"|\"reminders_by_template\"|\"reminders_by_level\"|\"reminders_by_course_details\"' /tmp/dtz_admin_progress.out || fail "admin_progress reminder payload invalid"
else
  echo "[SMOKE] admin creds not provided -> skipped"
fi

echo "[SMOKE] 4/5 student auth smoke (optional)"
if [[ -n "${STUDENT_USER:-}" && -n "${STUDENT_PASS:-}" ]]; then
  cat > /tmp/dtz_student_login.json <<JSON
{"username":"${STUDENT_USER}","password":"${STUDENT_PASS}"}
JSON
  student_code="$(curl -sS -c /tmp/dtz_student.cookie -o /tmp/dtz_student_login.out -w "%{http_code}" \
    -X POST "$BASE_URL/api/student_login.php" \
    -H "Content-Type: application/json" \
    --data-binary @/tmp/dtz_student_login.json)"
  [[ "$student_code" == "200" ]] || fail "student login failed ($student_code)"
  contains_match '"ok":true|"authenticated":true' /tmp/dtz_student_login.out || fail "student login response not authenticated"

  sp_code="$(curl -sS -b /tmp/dtz_student.cookie -o /tmp/dtz_student_portal.out -w "%{http_code}" "$BASE_URL/api/student_portal.php")"
  [[ "$sp_code" == "200" ]] || fail "student portal failed ($sp_code)"
  contains_match '"homeworks"|"authenticated"' /tmp/dtz_student_portal.out || fail "student portal payload invalid"
else
  echo "[SMOKE] student creds not provided -> skipped"
fi

echo "[SMOKE] 5/5 anti-repeat smoke (optional)"
if [[ "${ANTI_REPEAT_SMOKE:-0}" == "1" ]]; then
  "$ROOT_DIR/tools/anti_repeat_smoke.sh"
else
  echo "[SMOKE] ANTI_REPEAT_SMOKE=1 not set -> skipped"
fi

echo "[SMOKE] 6/6 modelltest pool smoke (optional)"
if [[ -n "${STUDENT_USER:-}" && -n "${STUDENT_PASS:-}" ]]; then
  if [[ ! -s /tmp/dtz_student.cookie ]]; then
    cat > /tmp/dtz_student_login.json <<JSON
{"username":"${STUDENT_USER}","password":"${STUDENT_PASS}"}
JSON
    student_code="$(curl -sS -c /tmp/dtz_student.cookie -o /tmp/dtz_student_login.out -w "%{http_code}" \
      -X POST "$BASE_URL/api/student_login.php" \
      -H "Content-Type: application/json" \
      --data-binary @/tmp/dtz_student_login.json)"
    [[ "$student_code" == "200" ]] || fail "student login failed for modelltest smoke ($student_code)"
    contains_match '"ok":true|"authenticated":true' /tmp/dtz_student_login.out || fail "student auth invalid for modelltest smoke"
  fi

  for module in hoeren lesen; do
    if [[ "$module" == "hoeren" ]]; then
      max_teil=4
    else
      max_teil=5
    fi
    for teil in $(seq 1 "$max_teil"); do
      out="/tmp/dtz_modelltest_${module}_${teil}.out"
      code="$(curl -sS -b /tmp/dtz_student.cookie -o "$out" -w "%{http_code}" \
        -X POST "$BASE_URL/api/student_training_set.php" \
        -H "Content-Type: application/json" \
        --data "{\"module\":\"${module}\",\"teil\":${teil},\"count\":1,\"pool\":\"modelltest\"}")"
      [[ "$code" == "200" ]] || fail "modelltest pool load failed (${module} teil ${teil}) code=$code"
      contains_match '"ok":true' "$out" || fail "modelltest pool payload invalid (${module} teil ${teil})"
      contains_match '"items":\\[' "$out" || fail "modelltest pool empty items (${module} teil ${teil})"
    done
  done
else
  echo "[SMOKE] student creds not provided -> modelltest pool smoke skipped"
fi

echo "[SMOKE] 7/7 modelltest assignment + schreiben smoke (optional)"
if [[ -n "${ADMIN_USER:-}" && -n "${ADMIN_PASS:-}" && -n "${STUDENT_USER:-}" && -n "${STUDENT_PASS:-}" ]]; then
  if [[ ! -s /tmp/dtz_admin.cookie ]]; then
    cat > /tmp/dtz_admin_login.json <<JSON
{"username":"${ADMIN_USER}","password":"${ADMIN_PASS}"}
JSON
    admin_code="$(curl -sS -c /tmp/dtz_admin.cookie -o /tmp/dtz_admin_login.out -w "%{http_code}" \
      -X POST "$BASE_URL/api/admin_login.php" \
      -H "Content-Type: application/json" \
      --data-binary @/tmp/dtz_admin_login.json)"
    [[ "$admin_code" == "200" ]] || fail "admin login failed for modelltest assign smoke ($admin_code)"
  fi

  now_iso="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
  cat > /tmp/dtz_modelltest_assign_create.json <<JSON
{
  "action": "create",
  "template_id": "dtz-mock-pruefung-komplett",
  "title": "SMOKE Modelltest mit Schreiben",
  "description": "Automated smoke assignment for modelltest + schreiben",
  "attachment": "mock_mail=1",
  "target_type": "users",
  "usernames": ["${STUDENT_USER}"],
  "duration_minutes": 20,
  "starts_at": "${now_iso}"
}
JSON
  create_code="$(curl -sS -b /tmp/dtz_admin.cookie -o /tmp/dtz_modelltest_assign_create.out -w "%{http_code}" \
    -X POST "$BASE_URL/api/homework_assign.php" \
    -H "Content-Type: application/json" \
    --data-binary @/tmp/dtz_modelltest_assign_create.json)"
  [[ "$create_code" == "200" ]] || fail "modelltest assignment create failed ($create_code)"
  modelltest_assignment_id="$(php -r '
    $j=json_decode(file_get_contents($argv[1]), true);
    $id=trim((string)($j["assignment_id"] ?? ""));
    if ($id === "") { exit(2); }
    echo $id;
  ' /tmp/dtz_modelltest_assign_create.out)" || fail "modelltest assignment_id missing"

  cleanup_modelltest_assignment() {
    curl -sS -b /tmp/dtz_admin.cookie \
      -X POST "$BASE_URL/api/homework_assign.php" \
      -H "Content-Type: application/json" \
      --data "{\"action\":\"delete\",\"assignment_id\":\"${modelltest_assignment_id}\"}" >/dev/null 2>&1 || true
  }
  trap cleanup_modelltest_assignment EXIT

  start_code="$(curl -sS -b /tmp/dtz_student.cookie -o /tmp/dtz_modelltest_start.out -w "%{http_code}" \
    -X POST "$BASE_URL/api/student_homework_start.php" \
    -H "Content-Type: application/json" \
    --data "{\"assignment_id\":\"${modelltest_assignment_id}\"}")"
  [[ "$start_code" == "200" ]] || fail "modelltest start failed ($start_code)"
  contains_match '"ok":true' /tmp/dtz_modelltest_start.out || fail "modelltest start payload invalid"

  save_code="$(curl -sS -b /tmp/dtz_student.cookie -o /tmp/dtz_modelltest_save.out -w "%{http_code}" \
    -X POST "$BASE_URL/api/student_modelltest_result.php" \
    -H "Content-Type: application/json" \
    --data "{\"assignment_id\":\"${modelltest_assignment_id}\",\"hoeren_correct\":16,\"hoeren_total\":20,\"lesen_correct\":18,\"lesen_total\":25,\"schreiben_score\":12,\"schreiben_max\":20}")"
  [[ "$save_code" == "200" ]] || fail "modelltest result save failed ($save_code)"
  contains_match '"ok":true' /tmp/dtz_modelltest_save.out || fail "modelltest result payload invalid"

  save2_code="$(curl -sS -b /tmp/dtz_student.cookie -o /tmp/dtz_modelltest_save2.out -w "%{http_code}" \
    -X POST "$BASE_URL/api/student_modelltest_result.php" \
    -H "Content-Type: application/json" \
    --data "{\"assignment_id\":\"${modelltest_assignment_id}\",\"hoeren_correct\":16,\"hoeren_total\":20,\"lesen_correct\":18,\"lesen_total\":25,\"schreiben_score\":12,\"schreiben_max\":20}")"
  [[ "$save2_code" == "409" ]] || fail "modelltest second save should be locked (expected 409, got $save2_code)"
else
  echo "[SMOKE] admin/student creds not provided -> modelltest assignment smoke skipped"
fi

echo "[SMOKE][OK] all enabled checks passed"
