from pathlib import Path

import pytest

from workers.api.config import load_catalog


REPO_ROOT = Path(__file__).resolve().parents[2]


def test_example_contains_verified_gonka_contract_without_media_routes(monkeypatch):
    monkeypatch.setenv("G4F_API_KEY", "test-g4f")
    monkeypatch.setenv("GONKA_API_KEY", "test-gonka")
    catalog = load_catalog(REPO_ROOT / "worker-api.example.yaml")

    assert catalog.providers["gonka"] == {
        "base_url": "https://gate.joingonka.ai",
        "schema": "gonka/v1",
    }
    gonka = [model for model in catalog.models.values() if model.provider_id == "gonka"]
    assert [(model.key, model.model_id, model.tiers) for model in gonka] == [
        ("gonka/fast", "MiniMaxAI/MiniMax-M2.7", ("fast",)),
        ("gonka/normal", "deepseek-ai/DeepSeek-V4-Flash-0731", ("normal",)),
        ("gonka/hard", "moonshotai/Kimi-K2.6", ("hard",)),
    ]
    assert catalog.operations["gonka/chat"].startup_mode == "models"
    assert catalog.public_pairs() == {"txt": ["json_ai", "txt_ai"]}
    assert "bearer_env: GONKA_API_KEY" in (REPO_ROOT / "worker-api.example.yaml").read_text(encoding="utf-8")


def test_gonka_rejects_model_id_not_in_immutable_manifest(tmp_path, monkeypatch):
    monkeypatch.setenv("G4F_API_KEY", "test-g4f")
    monkeypatch.setenv("GONKA_API_KEY", "test-gonka")
    config = (REPO_ROOT / "worker-api.example.yaml").read_text(encoding="utf-8")
    config = config.replace("MiniMaxAI/MiniMax-M2.7", "attacker/arbitrary-model")
    path = tmp_path / "config.yaml"
    path.write_text(config, encoding="utf-8")

    with pytest.raises(ValueError, match="manifest.*model"):
        load_catalog(path)


def test_gonka_rejects_pair_not_in_immutable_manifest(tmp_path, monkeypatch):
    monkeypatch.setenv("G4F_API_KEY", "test-g4f")
    monkeypatch.setenv("GONKA_API_KEY", "test-gonka")
    config = (REPO_ROOT / "worker-api.example.yaml").read_text(encoding="utf-8")
    config = config.replace("to: txt_ai", "to: image_ai")
    path = tmp_path / "config.yaml"
    path.write_text(config, encoding="utf-8")

    with pytest.raises(ValueError, match="manifest.*pair"):
        load_catalog(path)


def test_schema_ref_must_be_bound_to_its_provider_id(tmp_path, monkeypatch):
    monkeypatch.setenv("G4F_API_KEY", "test-g4f")
    monkeypatch.setenv("GONKA_API_KEY", "test-gonka")
    config = (REPO_ROOT / "worker-api.example.yaml").read_text(encoding="utf-8")
    config = config.replace("schema: gonka/v1", "schema: other/v1")
    path = tmp_path / "config.yaml"
    path.write_text(config, encoding="utf-8")

    with pytest.raises(ValueError, match="provider-bound schema reference"):
        load_catalog(path)
