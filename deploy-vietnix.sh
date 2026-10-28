#!/bin/bash
# Deploy GitHub main to the existing Vietnix FTP account for the test subdomain.
set -euo pipefail

lazi_dry_run=""
case "${1:-}" in
  "") ;;
  --dry-run) lazi_dry_run="--dry-run" ;;
  *) printf 'Cách dùng: bash deploy-vietnix.sh [--dry-run]\n' >&2; exit 2 ;;
esac
if [[ $# -gt 1 ]]; then
  printf 'Cách dùng: bash deploy-vietnix.sh [--dry-run]\n' >&2
  exit 2
fi

lazi_repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
lazi_lftp="$(command -v lftp || true)"
if [[ -z "$lazi_lftp" ]]; then
  for lazi_candidate in /opt/homebrew/bin/lftp /usr/local/bin/lftp; do
    if [[ -x "$lazi_candidate" ]]; then
      lazi_lftp="$lazi_candidate"
      break
    fi
  done
fi
if [[ -z "$lazi_lftp" ]]; then
  printf 'Chưa tìm thấy lftp. Cài bằng: brew install lftp\n' >&2
  exit 1
fi

# Fetch an immutable snapshot without switching branches or changing local files.
printf 'Đang lấy phiên bản main mới nhất từ GitHub...\n'
git -C "$lazi_repo" fetch --quiet origin refs/heads/main
lazi_revision="$(git -C "$lazi_repo" rev-parse --verify 'FETCH_HEAD^{commit}')"
lazi_stage="$(mktemp -d "${TMPDIR:-/tmp}/lazivoyage-deploy.XXXXXXXX")"
trap 'rm -rf -- "$lazi_stage"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
# Once CMS owns published HTML, a full repository upload would replace edits.
lazi_state_code="$(curl --silent --show-error --max-time 20 --write-out '%{http_code}' \
  --header 'Cache-Control: no-cache' 'https://sub.lazivoyage.com/cms/deployment-state.json' \
  -o "$lazi_stage/deployment-state.json")" || {
  printf 'Dừng: chưa kiểm tra được trạng thái CMS trên hosting.\n' >&2; exit 1;
}
if [[ "$lazi_state_code" == 200 ]]; then
  printf 'Dừng upload toàn site: hosting đã có CMS hoặc trả về trạng thái cần kiểm tra.\nDùng bash deploy-cms-vietnix.sh để cập nhật mã CMS và giữ nguyên nội dung.\n' >&2
  exit 1
elif [[ "$lazi_state_code" != 404 ]]; then
  printf 'Dừng: trạng thái CMS trả HTTP %s. Kiểm tra hosting trước khi upload.\n' "$lazi_state_code" >&2
  exit 1
fi
git -C "$lazi_repo" archive "$lazi_revision" | tar -xf - -C "$lazi_stage"
rm -f "$lazi_stage/deployment-state.json"
for lazi_required in index.html style.css script.js; do
  if [[ ! -f "$lazi_stage/$lazi_required" ]]; then
    printf 'Dừng: thiếu file website %s trong main.\n' "$lazi_required" >&2
    exit 1
  fi
done

printf 'Phiên bản: %s\nĐích: https://sub.lazivoyage.com/\n' "$lazi_revision"
printf 'Nhập mật khẩu FTP khi hiện Password (không phải mật khẩu Vietnix).\n'
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
mirror -R --verbose --no-perms --parallel=2 --max-errors=1 --exclude-glob '.git/' --exclude-glob '.github/' --exclude-glob '.DS_Store' --exclude-glob '.env*' --exclude-glob '.ftpquota' --exclude-glob '.gitignore' --exclude-glob 'deploy*.sh' --exclude-glob 'README*' --exclude-glob '*.md' --exclude-glob 'tests/' --exclude-glob 'cms/config.local.php' --exclude-glob 'cms/deployment-state.json' --exclude-glob 'uploads/cms/' $lazi_dry_run . /
bye
LFTP_COMMANDS
)"
"$lazi_lftp" --norc -c "$lazi_commands"

if [[ -n "$lazi_dry_run" ]]; then
  printf 'Đã xem trước. Chưa upload hay xóa file trên hosting.\n'
  exit 0
fi

printf 'Đã upload. Đang đối chiếu trang chủ, CSS và JavaScript qua HTTPS...\n'
for lazi_file in index.html style.css script.js; do
  lazi_url="https://sub.lazivoyage.com/$lazi_file"
  if ! curl --fail --silent --show-error --max-time 20 \
      --header 'Cache-Control: no-cache' "$lazi_url" -o "$lazi_stage/.verify-$lazi_file"; then
    printf 'Upload đã hoàn tất nhưng chưa kiểm tra được %s.\n' "$lazi_url" >&2
    exit 1
  fi
  if ! cmp -s "$lazi_stage/$lazi_file" "$lazi_stage/.verify-$lazi_file"; then
    printf 'Upload đã hoàn tất nhưng %s chưa khớp; cần kiểm tra cache hoặc Document Root.\n' "$lazi_url" >&2
    exit 1
  fi
done
printf 'Thành công: https://sub.lazivoyage.com/ đã khớp phiên bản %s.\n' "$lazi_revision"
