#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 3 ]]; then
  printf 'Usage: %s <exact-plugin.zip> <8.9.0|11.2.2> <legacy|hpos>\n' "$0" >&2
  exit 2
fi
plugin_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
root_dir="$plugin_dir"
archive="$(realpath "$1")"
version="$2"
case "$version" in
  8.9.0) expected_sha='f3132bb8b8cb8b06c753ebbfdcd129d66f5bac741c3548c8803f25f0f3374111' ;;
  11.2.2) expected_sha='a4746183efd951de5837396aad119f7cf508f06cb699ccb590a01cada4c44932' ;;
  *) printf 'Unsupported WooCommerce test version\n' >&2; exit 2 ;;
esac
case "$3" in
  legacy) hpos='no' ;;
  hpos) hpos='yes' ;;
  *) printf 'Use legacy or hpos\n' >&2; exit 2 ;;
esac
wc_zip="/tmp/digicyfr-woocommerce-${version}.zip"
if [[ ! -f "$wc_zip" ]]; then
  curl -fLsS "https://downloads.wordpress.org/plugin/woocommerce.${version}.zip" -o "$wc_zip"
fi
printf '%s  %s\n' "$expected_sha" "$wc_zip" | sha256sum --check
python3 "$root_dir/tools/verify_zip.py" "$archive" --slug digicyfr-product-data-audit --against-source
compose="$root_dir/tools/wordpress-test/compose.yaml"
project="digicyfr-dpda-${version//./-}-$3-$$"
port="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')"
docker_cmd=(docker)
compose_cmd=(env "DIGICYFR_TEST_PORT=$port" docker)
if ! docker info >/dev/null 2>&1; then docker_cmd=(sudo -n docker); compose_cmd=(sudo -n env "DIGICYFR_TEST_PORT=$port" docker); fi
cleanup() { "${compose_cmd[@]}" compose -p "$project" -f "$compose" down --volumes --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT
"${compose_cmd[@]}" compose -p "$project" -f "$compose" up -d --wait >/dev/null
container="$("${compose_cmd[@]}" compose -p "$project" -f "$compose" ps -q wordpress)"
for _ in {1..90}; do
  if "${docker_cmd[@]}" exec "$container" test -f /var/www/html/wp-load.php; then break; fi
  sleep 1
done
"${docker_cmd[@]}" exec "$container" test -f /var/www/html/wp-load.php
"${docker_cmd[@]}" cp "$root_dir/tools/wordpress-test/install-site.php" "$container:/tmp/digicyfr-install-site.php" >/dev/null
"${docker_cmd[@]}" cp "$root_dir/tools/wordpress-test/smoke-zip.php" "$container:/tmp/digicyfr-smoke.php" >/dev/null
"${docker_cmd[@]}" cp "$plugin_dir/tests/catalog-fixtures.php" "$container:/tmp/digicyfr-catalog-fixtures.php" >/dev/null
"${docker_cmd[@]}" cp "$plugin_dir/tests/set-hpos.php" "$container:/tmp/digicyfr-set-hpos.php" >/dev/null
"${docker_cmd[@]}" cp "$plugin_dir/tests/wp-integration.php" "$container:/tmp/digicyfr-wp-integration.php" >/dev/null
"${docker_cmd[@]}" cp "$wc_zip" "$container:/tmp/digicyfr-plugin.zip" >/dev/null
"${docker_cmd[@]}" exec -e "DIGICYFR_TEST_PORT=$port" "$container" php /tmp/digicyfr-install-site.php
"${docker_cmd[@]}" exec -e DIGICYFR_PLUGIN_SLUG=woocommerce -e "DIGICYFR_TEST_PORT=$port" "$container" php /tmp/digicyfr-smoke.php
"${docker_cmd[@]}" exec -e "DIGICYFR_TEST_PORT=$port" -e "DIGICYFR_HPOS_MODE=$hpos" "$container" php /tmp/digicyfr-set-hpos.php
"${docker_cmd[@]}" exec -e "DIGICYFR_TEST_PORT=$port" "$container" php /tmp/digicyfr-catalog-fixtures.php >/dev/null
"${docker_cmd[@]}" cp "$archive" "$container:/tmp/digicyfr-plugin.zip" >/dev/null
"${docker_cmd[@]}" exec -e DIGICYFR_PLUGIN_SLUG=digicyfr-product-data-audit -e "DIGICYFR_TEST_PORT=$port" "$container" php /tmp/digicyfr-smoke.php
"${docker_cmd[@]}" exec -e "DIGICYFR_TEST_PORT=$port" -e "DIGICYFR_HPOS_MODE=$hpos" "$container" php /tmp/digicyfr-wp-integration.php
