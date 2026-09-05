# Frontend contract QA

`converter_app_contract_harness.js` executes the published Alpine component with
Node and deterministic `fetch`, `localStorage`, `FormData`, and auth fixtures.
Run it through the repository target:

```sh
make -C app-symfony frontend-contract-test
```

## Reproducible mutation check

The following bounded mutation must make the OCR contract fixture fail. The
`trap` restores the template even when the test fails. Run from the repository
root; the expected output includes a non-zero Node exit followed by `restored`.

```sh
set -eu
script=app-symfony/templates/partials/_converter_app_script.html.twig
backup=$(mktemp)
cp "$script" "$backup"
restore() {
    cp "$backup" "$script"
    rm -f "$backup"
    printf 'restored\n'
}
trap restore EXIT
python3 - "$script" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
needle = 'if (this.ocr) return {};'
assert text.count(needle) == 1
path.write_text(text.replace(needle, "if (this.ocr) return {quality: 1};"))
PY
if node app-symfony/tests/Unit/Frontend/converter_app_contract_harness.js "$script" ocr-no-options; then
    echo 'mutation unexpectedly passed' >&2
    exit 1
fi
printf 'mutation failed as expected\n'
```
