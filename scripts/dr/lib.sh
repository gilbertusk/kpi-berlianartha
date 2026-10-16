# Shared helpers of scripts/backup.sh and scripts/dr-rehearsal.sh (sourced).
#
# Encryption is GnuPG symmetric AES-256 with its integrity check (MDC):
# decrypting a modified file fails instead of yielding altered plaintext.
# The passphrase is the first and only line of the key file and never
# appears on a command line.

# Key file: exactly one line of at least 32 base64/hex characters (e.g.
# `openssl rand -base64 48 > backup.key`), readable, outside the repository.
require_key_file() {
    local key_file="$1" root_dir="$2" first
    [ -n "$key_file" ] || fail 'BACKUP_ENCRYPTION_KEY_FILE wajib diisi (file kunci di luar repository).'
    [ -r "$key_file" ] || fail 'File kunci tidak dapat dibaca.'
    case "$(cd "$(dirname "$key_file")" && pwd)" in
        "$root_dir"*) fail 'File kunci tidak boleh berada di dalam repository.' ;;
    esac
    [ "$(grep -c '' "$key_file")" -eq 1 ] || fail 'File kunci harus berisi tepat satu baris.'
    first="$(head -n 1 "$key_file" | tr -d '\r')"
    [ "${#first}" -ge 32 ] || fail 'Kunci minimal 32 karakter.'
    printf '%s' "$first" | grep -Eq '^[A-Za-z0-9+/=_-]+$' || fail 'Kunci harus berupa base64 atau hex (tanpa spasi).'
}

# Private, throw-away GnuPG home: the operator keyring is never touched.
make_gnupg_home() {
    local home="$1"
    mkdir -p "$home"
    chmod 700 "$home"
}

gpg_encrypt() {
    local home="$1" key_file="$2" in="$3" out="$4"
    gpg --homedir "$home" --batch --yes --quiet --pinentry-mode loopback --passphrase-file "$key_file" \
        --symmetric --cipher-algo AES256 --s2k-mode 3 --s2k-digest-algo SHA512 --s2k-count 65011712 \
        --compress-algo none --force-mdc --output "$out" "$in"
}

gpg_decrypt() {
    local home="$1" key_file="$2" in="$3" out="$4"
    gpg --homedir "$home" --batch --yes --quiet --pinentry-mode loopback --passphrase-file "$key_file" \
        --output "$out" --decrypt "$in"
}
