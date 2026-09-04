"""Static guards for the production-only worker-api operations contract."""

import os
import re
import shutil
import subprocess
import tempfile
from collections.abc import Iterator
from pathlib import Path

import pytest


ROOT_DIR = Path(__file__).resolve().parents[2]


def _read(relative_path: str) -> str:
    return (ROOT_DIR / relative_path).read_text(encoding="utf-8")


def _target_recipe(makefile: str, target: str) -> str:
    marker = f"\n{target}:"
    start = makefile.index(marker) + 1
    end = makefile.find("\n.PHONY:", start)
    return makefile[start:] if end == -1 else makefile[start:end]


def _compose_service(compose: str, service: str) -> str:
    marker = f"    {service}:\n"
    start = compose.index(marker)
    next_service = re.search(
        r"^    [a-zA-Z0-9_-]+:$",
        compose[start + len(marker) :],
        re.MULTILINE,
    )
    if next_service is None:
        return compose[start:]
    end = start + len(marker) + next_service.start()
    return compose[start:end]


def _compose_profiles(service: str) -> set[str]:
    match = re.search(r"^        profiles: \[(.*)]$", service, re.MULTILINE)
    assert match is not None
    return {
        profile.strip().strip('"\'')
        for profile in match.group(1).split(",")
        if profile.strip()
    }


def _environment_profiles(relative_path: str) -> set[str]:
    assignments = [
        line.partition("=")[2]
        for line in _read(relative_path).splitlines()
        if line.startswith("COMPOSE_PROFILES=")
    ]
    assert len(assignments) == 1
    return {profile for profile in assignments[0].split(",") if profile}


def test_worker_api_image_has_version_metadata_and_catalog_healthcheck() -> None:
    dockerfile = _read("docker/workers/api.Dockerfile")

    assert "ARG APP_VER=0" in dockerfile
    assert "ARG WORKER_BUILD=0" in dockerfile
    assert "ENV APP_VER=${APP_VER}" in dockerfile
    assert "APP_VERSION=${APP_VER}" in dockerfile
    assert "WORKER_BUILD=${WORKER_BUILD}" in dockerfile
    assert 'RUN printf \'%s\' "${WORKER_BUILD}" > /app/.i' in dockerfile
    assert "HEALTHCHECK" in dockerfile
    assert "load_catalog(Path('/app/worker-api.yaml'))" in dockerfile
    assert "G4F_API_KEY" not in dockerfile


def test_worker_api_profile_is_enabled_only_on_the_main_server() -> None:
    worker_api = _compose_service(_read("docker-compose.yml"), "worker-api")
    main_profiles = _environment_profiles(".env")
    test_profiles = _environment_profiles(".env.test")
    remote_worker_profiles = _environment_profiles(".env.local_worker_example")
    makefile = _read("workers/Makefile")

    assert _compose_profiles(worker_api) == {"api"}
    assert "api" in main_profiles
    assert "api" not in test_profiles
    assert "api" not in remote_worker_profiles
    assert "COMPOSE_PROFILES=server,api" in _target_recipe(
        makefile, "worker-api-recreate"
    )
    assert "COMPOSE_PROFILES=server,api" in _target_recipe(
        makefile, "server-worker-logs"
    )


def test_each_standard_worker_has_a_dedicated_compose_profile() -> None:
    compose = _read("docker-compose.yml")
    expected = {
        "worker-libreoffice": "document",
        "worker-ffmpeg-audio": "audio",
        "worker-ffmpeg-video": "video",
        "worker-image": "image",
        "worker-data": "data",
        "worker-ai": "ai",
    }
    for service, profile in expected.items():
        assert _compose_profiles(_compose_service(compose, service)) == {profile}

    assert _environment_profiles(".env") >= set(expected.values())
    assert _environment_profiles(".env.local_worker_example") == set(expected.values())

    makefile = _read("workers/Makefile")
    recreate = _target_recipe(makefile, "workers-recreate")
    assert "SERVICE=worker-data" in recreate
    assert "unknown SERVICE" in recreate
    assert "--force-recreate --no-deps \"$$service\"" in recreate


def test_worker_api_compose_is_production_profiled_logged_and_bounded() -> None:
    compose = _read("docker-compose.yml")
    worker_api = compose[compose.index("    worker-api:") : compose.index("    ws-gateway:")]
    logging = _read("docker/fluent-logging.yml")
    worker_api_logging = logging[
        logging.index("    worker-api:") : logging.index("    ws-gateway:")
    ]
    limits = _read("docker/limits.yml")
    worker_api_limits = limits[
        limits.index("    worker-api:") : limits.index("    worker-ffmpeg-audio:")
    ]

    assert 'profiles: ["api"]' in worker_api
    assert "healthcheck:" in worker_api
    assert "load_catalog(Path('/app/worker-api.yaml'))" in worker_api
    assert "resources:" in worker_api_limits
    assert "limits:" in worker_api_limits
    assert "reservations:" in worker_api_limits
    assert 'cpus: "0.5"' in worker_api_limits
    assert 'memory: "256M"' in worker_api_limits
    assert 'cpus: "0.05"' in worker_api_limits
    assert 'memory: "64M"' in worker_api_limits
    assert 'tier: "worker"' in worker_api_logging
    assert 'log_format: "auto"' in worker_api_logging


def test_worker_api_make_targets_keep_generic_operations_remote_safe() -> None:
    makefile = _read("workers/Makefile")
    build_workers = _target_recipe(makefile, "build-workers")
    build_server_workers = _target_recipe(makefile, "build-server-workers")
    generic_recreate = _target_recipe(makefile, "workers-recreate")
    api_recreate = _target_recipe(makefile, "worker-api-recreate")
    worker_logs = _target_recipe(makefile, "worker-logs")
    server_worker_logs = _target_recipe(makefile, "server-worker-logs")
    release_workers = _target_recipe(makefile, "release-workers")
    test_images = _target_recipe(makefile, "build-workers-test")

    build_worker_dependencies = build_workers.partition(":")[2].partition("##")[0].split()
    build_server_dependencies = build_server_workers.partition(":")[2].partition("##")[0].split()
    release_dependencies = release_workers.partition(":")[2].partition("##")[0].split()
    generic_recreate_command = "\n".join(
        line for line in generic_recreate.splitlines() if line.startswith("\t")
    )
    worker_logs_command = "\n".join(
        line for line in worker_logs.splitlines() if line.startswith("\t")
    )
    server_worker_logs_command = "\n".join(
        line for line in server_worker_logs.splitlines() if line.startswith("\t")
    )

    assert build_worker_dependencies == [
        "build-libreoffice",
        "build-ffmpeg",
        "build-image",
        "build-data",
        "build-metrics-exporter",
        "build-host-telemetry",
        "build-gateway",
    ]
    assert build_server_dependencies == ["build-workers", "build-api"]
    assert "worker-api" not in generic_recreate_command
    assert "WORKER_RECREATE_PROFILE" in generic_recreate
    assert "WORKER_RECREATE_SERVICES" in generic_recreate
    assert "not allowed by profile" in generic_recreate
    assert "effective Compose service set" in generic_recreate
    assert "--force-recreate --no-deps $$effective" in generic_recreate
    assert "COMPOSE_PROFILES=server,api" in api_recreate
    assert "--force-recreate --no-deps worker-api" in api_recreate
    assert "worker-api" not in worker_logs_command
    assert worker_logs_command.count("worker-") == 6
    assert "COMPOSE_PROFILES=server,api" in server_worker_logs_command
    assert server_worker_logs_command.count("worker-") == 7
    assert "build-server-workers" in release_dependencies
    assert "build-api-test" in test_images
    assert len(test_images.partition(":")[2].partition("##")[0].split()) == 6
    assert "worker-api" in makefile[makefile.index("RELEASE_IMAGES :=") :].splitlines()[0]


def test_host_telemetry_is_in_sanctioned_build_release_and_deploy_contract() -> None:
    makefile = _read("workers/Makefile")
    dockerfile = _read("docker/workers/host-telemetry.Dockerfile")
    deploy_compose = _read("deploy/docker-compose.yml")
    build_target = _target_recipe(makefile, "build-host-telemetry")

    build_workers = _target_recipe(makefile, "build-workers")
    release_images = makefile[makefile.index("RELEASE_IMAGES :=") :].splitlines()[0]

    assert "build-host-telemetry" in build_workers
    assert "$(call build_img,worker-host-telemetry,host-telemetry)" in build_target
    assert "worker-host-telemetry" in release_images
    assert "image: ${IMAGE_NS:-harbor.xakki.ru/convertor}/worker-host-telemetry:${IMAGE_TAG:-latest}" in deploy_compose

    assert "ARG APP_VER=0" in dockerfile
    assert "LABEL org.opencontainers.image.version=${APP_VER}" in dockerfile
    assert "USER collector" in dockerfile
    assert 'ENTRYPOINT ["python3", "-m", "workers.host_telemetry"]' in dockerfile
    assert "requirements-host-telemetry.txt" in dockerfile
    assert "worker-ai-cuda" not in build_workers
    assert "worker-ai-cuda" not in release_images


def test_worker_recreate_profiles_keep_savpn_and_cpu_scope_explicit() -> None:
    makefile = _read("workers/Makefile")
    recreate = _target_recipe(makefile, "workers-recreate")

    assert "remote|uBook|ubook" in recreate
    assert "saVpn) allowed=\"$(WORKER_RECREATE_SAVPN_SERVICES)\"" in recreate
    assert "WORKER_RECREATE_SAVPN_SERVICES := worker-data worker-image" in makefile
    assert "WORKER_RECREATE_LOCAL_SERVICES := $(WORKER_RECREATE_REMOTE_SERVICES)" in makefile
    assert "worker-libreoffice worker-ffmpeg-audio worker-ffmpeg-video worker-image worker-data worker-ai" in makefile


def test_release_version_is_bumped_for_worker_rollout() -> None:
    env = _read(".env")
    assert "APP_VER=0.1.2" in env
    assert "APP_VER=0.1.1" not in env


def test_cuda_config_rejects_missing_or_empty_ai_image(tmp_path: Path) -> None:
    fake_compose, log = _fake_compose(tmp_path, "worker-ai")

    for command_line_ai_image, inherited_ai_image in (("AI_IMAGE=", "ignored"), (None, None)):
        env = os.environ.copy()
        env["FAKE_COMPOSE_LOG"] = str(log)
        if inherited_ai_image is None:
            env.pop("AI_IMAGE", None)
        else:
            env["AI_IMAGE"] = inherited_ai_image
        command = [
            "make",
            "--no-print-directory",
            "-C",
            str(ROOT_DIR),
            "config-check",
            "DC=" + fake_compose,
            "AI_VARIANT=cuda",
        ]
        if command_line_ai_image is not None:
            command.append(command_line_ai_image)

        result = subprocess.run(command, env=env, text=True, capture_output=True, check=False)
        output = result.stdout + result.stderr
        assert result.returncode != 0
        assert "AI_IMAGE is required when AI_VARIANT=cuda" in output
        assert "harbor.xakki.ru/convertor/worker-ai-cuda" not in output

    assert not log.exists()


def test_ai_cuda_build_and_compose_use_local_app_ver_tags() -> None:
    makefile = _read("workers/Makefile")
    compose = _read("docker-compose.yml")
    example = _read(".env.local_worker_example")

    cuda_build = subprocess.run(
        ["make", "--no-print-directory", "-n", "build-ai-cuda", "APP_VER=0.1.2"],
        cwd=ROOT_DIR,
        text=True,
        capture_output=True,
        check=False,
    )
    assert cuda_build.returncode == 0, cuda_build.stdout + cuda_build.stderr
    assert "-t worker-ai-cuda:0.1.2 -t worker-ai-cuda:latest" in cuda_build.stdout
    assert "worker-ai:0.1.2-cuda" not in cuda_build.stdout
    assert "worker-ai-cuda:0.1.2-cuda" not in cuda_build.stdout

    assert "AI_IMAGE=worker-ai-cuda:latest" in example
    assert "image: ${AI_IMAGE:-${IMAGE_NS}/worker-ai-${AI_VARIANT:-cpu}:${IMAGE_TAG:-latest}}" in compose
    env = os.environ.copy()
    env.update(AI_VARIANT="cuda", AI_IMAGE="worker-ai-cuda:0.1.2", IMAGE_TAG="0.1.2")
    # .env.test intentionally omits ai so test runtime does not start worker-ai.
    # This config-only assertion enables ai for its single Compose inspection.
    env["COMPOSE_PROFILES"] = "server,test,ai"
    compose_config = subprocess.run(
        ["docker", "compose", "config", "--images"],
        cwd=ROOT_DIR,
        env=env,
        text=True,
        capture_output=True,
        check=False,
    )
    assert compose_config.returncode == 0, compose_config.stdout + compose_config.stderr
    assert "worker-ai-cuda:0.1.2" in compose_config.stdout
    assert "AI_CUDA_IMAGE   ?= worker-ai-cuda:$(APP_VER)" in makefile
    assert "-t $(HARBOR_NS)/worker-ai-base:$(APP_VER)" in makefile
    assert "-t $(HARBOR_NS)/worker-ai-base:latest" in makefile
    assert "LABEL org.opencontainers.image.version=${APP_VER}" in _read(
        "docker/workers/ai-base.Dockerfile"
    )


def _fake_compose(tmp_path: Path, services: str) -> tuple[str, Path]:
    script = tmp_path / "fake-compose"
    log = tmp_path / "compose.log"
    script.write_text(
        "#!/bin/sh\n"
        "printf '%s|%s|%s\\n' \"$COMPOSE_PROFILES\" \"$1\" \"$*\" >> \"$FAKE_COMPOSE_LOG\"\n"
        "if [ \"$1\" = config ] && [ \"$2\" = --services ]; then\n"
        f"  printf '%s\\n' {services}\n"
        "  exit 0\n"
        "fi\n"
        "if [ \"$1\" = up ]; then exit 0; fi\n"
        "exit 3\n",
        encoding="utf-8",
    )
    script.chmod(0o755)
    return str(script), log


def _fake_docker(tmp_path: Path) -> Path:
    docker = tmp_path / "docker"
    docker.write_text(
        "#!/bin/sh\n"
        "if [ \"$1\" = compose ] && [ \"$2\" = -p ] && [ \"$4\" = ps ] && [ \"$5\" = -q ]; then\n"
        "  printf 'fake-container\\n'\n"
        "  exit 0\n"
        "fi\n"
        "if [ \"$1\" = inspect ]; then\n"
        f"  printf '%s\\n' {os.getpid()}\n"
        "  exit 0\n"
        "fi\n"
        "exit 3\n",
        encoding="utf-8",
    )
    docker.chmod(0o755)
    return docker


@pytest.fixture
def host_root_probe_dir() -> Iterator[Path]:
    probe_dir = Path(
        tempfile.mkdtemp(prefix="convertor-test-host-root-probe-", dir="/var/tmp")
    )
    try:
        yield probe_dir
    finally:
        shutil.rmtree(probe_dir, ignore_errors=True)


def _run_recreate(
    tmp_path: Path,
    profile: str,
    services: str,
    compose_services: str,
    host_root_probe_dir: Path,
) -> subprocess.CompletedProcess[str]:
    fake_compose, log = _fake_compose(tmp_path, compose_services)
    fake_docker = _fake_docker(tmp_path)
    env = os.environ.copy()
    env["FAKE_COMPOSE_LOG"] = str(log)
    env["HOST_ROOT_PROBE_DIR"] = str(host_root_probe_dir)
    env["PATH"] = f"{fake_docker.parent}:{env['PATH']}"
    return subprocess.run(
        [
            "make",
            "--no-print-directory",
            "-C",
            str(ROOT_DIR),
            "workers-recreate",
            f"DC={fake_compose}",
            f"WORKER_RECREATE_PROFILE={profile}",
            f"WORKER_RECREATE_SERVICES={services}",
        ],
        cwd=ROOT_DIR,
        env=env,
        text=True,
        capture_output=True,
        check=False,
    )


@pytest.mark.parametrize("image_arg", ["AI_IMAGE=", None])
def test_workers_recreate_rejects_missing_cuda_image_before_compose_invocation(
    tmp_path: Path,
    image_arg: str | None,
) -> None:
    fake_compose, log = _fake_compose(tmp_path, "worker-ai")
    env = os.environ.copy()
    env.pop("AI_IMAGE", None)
    env["FAKE_COMPOSE_LOG"] = str(log)
    command = [
        "make",
        "--no-print-directory",
        "-C",
        str(ROOT_DIR),
        "workers-recreate",
        "DC=" + fake_compose,
        "AI_VARIANT=cuda",
        "WORKER_RECREATE_PROFILE=remote",
        "WORKER_RECREATE_SERVICES=worker-ai",
    ]
    if image_arg is not None:
        command.append(image_arg)

    result = subprocess.run(
        command,
        cwd=ROOT_DIR,
        env=env,
        text=True,
        capture_output=True,
        check=False,
    )

    assert result.returncode != 0
    assert "AI_IMAGE is required when AI_VARIANT=cuda" in result.stdout + result.stderr
    assert not log.exists()


def test_workers_recreate_accepts_savpn_data_and_image_without_whitespace_matching(
    tmp_path: Path,
    host_root_probe_dir: Path,
) -> None:
    result = _run_recreate(
        tmp_path,
        "saVpn",
        "worker-data worker-image",
        "worker-data worker-image",
        host_root_probe_dir,
    )

    assert result.returncode == 0, result.stdout + result.stderr
    assert "effective services=worker-data worker-image" in result.stdout
    assert "|up|up -d --force-recreate --no-deps worker-data worker-image" in (
        tmp_path / "compose.log"
    ).read_text(encoding="utf-8")


@pytest.mark.parametrize(
    ("profile", "services", "compose_services", "expected_profiles"),
    [
        ("local", "worker-data", "worker-data", "data"),
        ("uBook", "worker-ai", "worker-ai", "ai"),
    ],
)
def test_workers_recreate_sets_effective_ai_profile_for_local_and_ubook(
    tmp_path: Path,
    host_root_probe_dir: Path,
    profile: str,
    services: str,
    compose_services: str,
    expected_profiles: str,
) -> None:
    result = _run_recreate(
        tmp_path,
        profile,
        services,
        compose_services,
        host_root_probe_dir,
    )

    assert result.returncode == 0, result.stdout + result.stderr
    calls = (tmp_path / "compose.log").read_text(encoding="utf-8").splitlines()
    assert calls[0].startswith(f"{expected_profiles}|config|")
    assert calls[1].startswith(f"{expected_profiles}|up|")


@pytest.mark.parametrize("target", ["fluent-up", "fluent-recreate", "fluent-restart"])
def test_fluent_compose_targets_are_independent_of_cuda_guard(target: str) -> None:
    recipe = _target_recipe(_read("workers/Makefile"), target)

    assert "validate-ai-image" not in recipe
    assert "$(DC_FLUENT)" in recipe


@pytest.mark.parametrize(
    ("profile", "services", "compose_services"),
    [
        ("unknown", "worker-data", "worker-data"),
        ("saVpn", "worker-ai", "worker-ai"),
        ("remote", "worker-api", "worker-api"),
    ],
)
def test_workers_recreate_rejects_invalid_scope_before_up(
    tmp_path: Path,
    host_root_probe_dir: Path,
    profile: str,
    services: str,
    compose_services: str,
) -> None:
    result = _run_recreate(
        tmp_path,
        profile,
        services,
        compose_services,
        host_root_probe_dir,
    )

    assert result.returncode == 2
    compose_log = tmp_path / "compose.log"
    assert "up|" not in (compose_log.read_text(encoding="utf-8") if compose_log.exists() else "")
