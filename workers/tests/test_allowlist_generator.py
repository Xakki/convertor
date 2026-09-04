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
    cgroup_root = tmp_path / "cgroup"
    (cgroup_root / "compose/data").mkdir(parents=True)
    (cgroup_root / "compose/image").mkdir()
    data = module.build(["worker-data", "worker-image"], {"worker-data": "compose/data", "worker-image": "compose/image"})
    module.validate(data, cgroup_root)
    output.write_text(json.dumps(data))
    loaded = json.loads(output.read_text())
    assert loaded["version"] == 1
    assert loaded["provenance"]["source"] == "deployment"
    assert loaded["workers"] == {
        "worker-data": "compose/data",
        "worker-image": "compose/image",
    }


def test_allowlist_rejects_empty_and_metadata_paths():
    try:
        module.build([], {})
    except ValueError:
        pass
    else:
        raise AssertionError("empty allowlist accepted")
    try:
        module.validate({"version": 1, "provenance": {"source": "deployment"}, "workers": {"x": "../proc"}})
    except ValueError:
        pass
    else:
        raise AssertionError("metadata path accepted")


def test_allowlist_requires_actual_mapping():
    try:
        module.build(["worker-data"])
    except ValueError:
        pass
    else:
        raise AssertionError("fictional mapping accepted")


def test_initial_allowlist_is_regular_empty_mapping_without_worker_claims(tmp_path: Path):
    output = tmp_path / "allowlist.json"
    data = module.build_initial()
    module.validate(data)
    module.write_atomic(output, data)
    assert output.is_file() and not output.is_symlink()
    assert json.loads(output.read_text()) == {
        "version": 1,
        "provenance": {"source": "deployment", "format": "initial-empty-v1"},
        "workers": {},
    }


def test_main_up_allows_host_only_without_inactive_worker_profiles():
    makefile = (MODULE_PATH.parents[1] / "Makefile").read_text()
    assert "WORKER_SERVICES ?=" in makefile
    assert "generate-allowlist.py --initial" in makefile
    assert "workers-recreate" in (MODULE_PATH.parents[1] / "workers" / "Makefile").read_text()

def test_install_lifecycle_initializes_before_transition_and_generates_after_transition():
    script = (MODULE_PATH.parent / "install.sh").read_text()
    assert "--initial" in script
    assert script.index("ensure_initial_allowlist") < script.index("bring_up()")
    bring_up = script[script.index("bring_up()"):script.index("# --- main")]
    assert bring_up.index("backup_allowlist") < bring_up.index('compose "${PROFILE_ARGS[@]}" up')
    assert bring_up.index('compose "${PROFILE_ARGS[@]}" up') < bring_up.index("activate_allowlist")
    assert bring_up.index("clear_allowlist_backup") > bring_up.index("compose ps")
    assert "activate_allowlist\n    bring_up" not in script
