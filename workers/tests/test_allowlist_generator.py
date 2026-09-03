import importlib.util
import json
from pathlib import Path


MODULE_PATH = Path(__file__).parents[2] / "deploy" / "generate-allowlist.py"
spec = importlib.util.spec_from_file_location("generate_allowlist", MODULE_PATH)
assert spec and spec.loader
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def test_allowlist_is_versioned_and_provenanced(tmp_path: Path):
    output = tmp_path / "allowlist.json"
    data = module.build(["worker-data", "worker-image"])
    module.validate(data)
    output.write_text(json.dumps(data))
    loaded = json.loads(output.read_text())
    assert loaded["version"] == 1
    assert loaded["provenance"]["source"] == "deployment"
    assert loaded["workers"] == {
        "worker-data": "convertor-workers/worker-data",
        "worker-image": "convertor-workers/worker-image",
    }


def test_allowlist_rejects_empty_and_metadata_paths():
    try:
        module.build([])
    except ValueError:
        pass
    else:
        raise AssertionError("empty allowlist accepted")
    try:
        module.validate({"version": 1, "provenance": {"source": "deployment"}, "workers": {"x": "docker/containers/id"}})
    except ValueError:
        pass
    else:
        raise AssertionError("metadata path accepted")
