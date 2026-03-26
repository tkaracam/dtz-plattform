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
need_cmd rg

echo "[SMOKE] 1/5 PHP lint"
while IFS= read -r f; do
  php -l "$f" >/dev/null || fail "php lint failed: $f"
done < <(cd "$ROOT_DIR" && rg --files api | rg '\.php$')

echo "[SMOKE] 2/5 unauth endpoint checks"
resp_code="$(curl -sS -o /tmp/dtz_smoke_home.out -w "%{http_code}" "$BASE_URL/")"
[[ "$resp_code" == "200" ]] || fail "GET / returned $resp_code"
rg -q "dtz-build|DTZ|dtz" /tmp/dtz_smoke_home.out || fail "home page marker not found"

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
  rg -q '"ok":true|"authenticated":true' /tmp/dtz_admin_login.out || fail "admin login response not authenticated"

  report_code="$(curl -sS -b /tmp/dtz_admin.cookie -o /tmp/dtz_admin_report.out -w "%{http_code}" \
    -X POST "$BASE_URL/api/homework_assign.php" \
    -H "Content-Type: application/json" \
    --data '{"action":"dtz_usage_report"}')"
  [[ "$report_code" == "200" ]] || fail "dtz_usage_report failed ($report_code)"
  rg -q '"ok":true' /tmp/dtz_admin_report.out || fail "dtz_usage_report response invalid"

  progress_code="$(curl -sS -b /tmp/dtz_admin.cookie -o /tmp/dtz_admin_progress.out -w "%{http_code}" \
    "$BASE_URL/api/admin_progress.php?days=7")"
  [[ "$progress_code" == "200" ]] || fail "admin_progress failed ($progress_code)"
  rg -q '"summary"|\"courses\"|\"students\"' /tmp/dtz_admin_progress.out || fail "admin_progress base payload invalid"
  rg -q '"reminders_by_course"|\"reminders_by_template\"|\"reminders_by_level\"|\"reminders_by_course_details\"' /tmp/dtz_admin_progress.out || fail "admin_progress reminder payload invalid"
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
  rg -q '"ok":true|"authenticated":true' /tmp/dtz_student_login.out || fail "student login response not authenticated"

  sp_code="$(curl -sS -b /tmp/dtz_student.cookie -o /tmp/dtz_student_portal.out -w "%{http_code}" "$BASE_URL/api/student_portal.php")"
  [[ "$sp_code" == "200" ]] || fail "student portal failed ($sp_code)"
  rg -q '"homeworks"|"authenticated"' /tmp/dtz_student_portal.out || fail "student portal payload invalid"
else
  echo "[SMOKE] student creds not provided -> skipped"
fi

echo "[SMOKE] 5/5 anti-repeat smoke (optional)"
if [[ "${ANTI_REPEAT_SMOKE:-0}" == "1" ]]; then
  "$ROOT_DIR/tools/anti_repeat_smoke.sh"
else
  echo "[SMOKE] ANTI_REPEAT_SMOKE=1 not set -> skipped"
fi

echo "[SMOKE][OK] all enabled checks passed"
