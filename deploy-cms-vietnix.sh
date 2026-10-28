#!/bin/bash
# Update CMS application code without replacing content published by the CMS.
set -euo pipefail
lazi_dry_run=""
case "${1:-}" in
  "") ;;
  --dry-run) lazi_dry_run="--dry-run" ;;
  *) printf 'Cách dùng: bash deploy-cms-vietnix.sh [--dry-run]\n' >&2; exit 2 ;;
esac
[[ $# -le 1 ]] || exit 2
lazi_repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
lazi_lftp="$(command -v lftp || true)"
for lazi_candidate in /opt/homebrew/bin/lftp /usr/local/bin/lftp; do
  if [[ -z "$lazi_lftp" && -x "$lazi_candidate" ]]; then lazi_lftp="$lazi_candidate"; fi
done
[[ -n "$lazi_lftp" ]] || { printf 'Cần cài lftp: brew install lftp\n' >&2; exit 1; }
git -C "$lazi_repo" fetch --quiet origin refs/heads/main
lazi_revision="$(git -C "$lazi_repo" rev-parse --verify 'FETCH_HEAD^{commit}')"
lazi_stage="$(mktemp -d "${TMPDIR:-/tmp}/lazi-cms-deploy.XXXXXXXX")"
trap 'rm -rf -- "$lazi_stage"' EXIT
git -C "$lazi_repo" archive "$lazi_revision" admin cms | tar -xf - -C "$lazi_stage"
for lazi_required in admin/index.html cms/api.php cms/console.php; do
  [[ -f "$lazi_stage/$lazi_required" ]] || { printf 'Thiếu %s trong main.\n' "$lazi_required" >&2; exit 1; }
done
printf 'Cập nhật mã CMS từ phiên bản %s\nĐích: https://sub.lazivoyage.com/admin/\n' "$lazi_revision"
printf 'Nhập mật khẩu FTP khi hiện Password. Bài viết, ảnh upload và dữ liệu tài khoản được giữ trên hosting.\n'
cd -- "$lazi_stage"
lazi_commands="$(cat <<LFTP_COMMANDS
set cmd:fail-exit true
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ssl:verify-certificate true
set ftp:passive-mode true
set net:timeout 20
set net:max-retries 2
open -u 'lazivoyage@sub.lazivoyage.com' -p 21 ftp://host142.vietnix.vn
mirror -R --verbose --no-perms --parallel=2 --max-errors=1 --exclude-glob 'config.local.php' --exclude-glob 'deployment-state.json' --exclude-glob '.DS_Store' $lazi_dry_run admin /admin
mirror -R --verbose --no-perms --parallel=2 --max-errors=1 --exclude-glob 'config.local.php' --exclude-glob 'deployment-state.json' --exclude-glob '.DS_Store' $lazi_dry_run cms /cms
bye
LFTP_COMMANDS
)"
"$lazi_lftp" --norc -c "$lazi_commands"
if [[ -n "$lazi_dry_run" ]]; then printf 'Đã xem trước, chưa upload.\n'; exit 0; fi
curl --fail --silent --show-error --max-time 20 'https://sub.lazivoyage.com/admin/' -o "$lazi_stage/admin-check.html"
cmp -s "$lazi_stage/admin/index.html" "$lazi_stage/admin-check.html" || { printf 'Upload hoàn tất nhưng giao diện chưa khớp. Kiểm tra cache/document root.\n' >&2; exit 1; }
curl --fail --silent --show-error --max-time 20 'https://sub.lazivoyage.com/cms/api.php?action=session' -o "$lazi_stage/session-check.json"
if ! LC_ALL=C /usr/bin/grep -q '"csrf"' "$lazi_stage/session-check.json"; then
  printf 'Giao diện đã upload nhưng backend cần kiểm tra. Chạy php cms/console.php doctor trong cPanel Terminal.\n' >&2
  exit 1
fi
printf 'Đã cập nhật CMS. Mở https://sub.lazivoyage.com/admin/ để kiểm tra.\n'
