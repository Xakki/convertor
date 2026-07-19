## Don't use alone — included into the parent Makefile

# On-server worker image name prefix (COMPOSE_PROJECT_NAME from the root .env).
IMG = $(COMPOSE_PROJECT_NAME)

# Worker build-версия (§4/§8): полная version = APP_VER (.env) + монотонный build-счётчик
# из gitignored `.i` в корне репо → напр. "0.1.6". Запекается в образ (ARG→ENV APP_VER +
# /app/.i); воркер РЕПОРТИТ её в `ready` (транспорт-only, gateway не потребляет).
# Инкремент — одна строка: bump-i (prereq у build-таргетов воркеров, выполняется раз за make).
.PHONY: bump-i
bump-i: ## Инкремент build-счётчика .i (создаёт при отсутствии)
	@n=$$(cat $(CURDIR)/.i 2>/dev/null || echo 0); n=$$((n+1)); printf '%s' "$$n" > $(CURDIR)/.i; echo "build counter .i → $$n"

# Build one on-server worker image. $(1)=image stem (the /<stem>:latest tag),
# $(2)=dockerfile stem (docker/workers/<stem>.Dockerfile). Most workers share the
# stem; metrics-exporter does NOT (tag has no `worker-` prefix, dockerfile is
# metrics_exporter.Dockerfile) — hence two params. APP_VER + WORKER_BUILD запекают
# version в образ (worker-Dockerfile'ы: ARG→ENV APP_VER + echo WORKER_BUILD > /app/.i).
define build_img
docker build -t $(IMG)/$(1):latest \
    --build-arg APP_VER=$(APP_VER) \
    --build-arg WORKER_BUILD="$$(cat $(CURDIR)/.i 2>/dev/null || echo 0)" \
    -f docker/workers/$(2).Dockerfile .
endef

# Build a pre-baked :test image ON TOP of an existing :latest worker image.
# Test deps are baked in — no pip install at runtime.  $(1)=image stem.
define build_test_img
docker build --build-arg BASE_IMAGE=$(IMG)/$(1):latest \
    -t $(IMG)/$(1):test -f docker/workers/test.Dockerfile .
endef

##@ Build individual worker images

.PHONY: build-libreoffice
build-libreoffice: bump-i ## Build worker-libreoffice image
	$(call build_img,worker-libreoffice,libreoffice)

.PHONY: build-ffmpeg
build-ffmpeg: bump-i ## Build worker-ffmpeg image
	$(call build_img,worker-ffmpeg,ffmpeg)

.PHONY: build-image
build-image: bump-i ## Build worker-image image
	$(call build_img,worker-image,image)

.PHONY: build-data
build-data: bump-i ## Build worker-data image
	$(call build_img,worker-data,data)

.PHONY: build-metrics-exporter
build-metrics-exporter: ## Build metrics-exporter image
	$(call build_img,metrics-exporter,metrics_exporter)

.PHONY: build-gateway
build-gateway: ## Build ws-gateway image (Harbor-suitable)
	$(call build_img,ws-gateway,gateway)

.PHONY: push-gateway
push-gateway: build-gateway ## Build + push ws-gateway в Harbor (проект convertor); нужен `make login`
	docker tag $(IMG)/ws-gateway:latest $(HARBOR_NS)/ws-gateway:latest
	docker push $(HARBOR_NS)/ws-gateway:latest

.PHONY: gateway-up
gateway-up: ## Start только ws-gateway (глобальный `make up` тоже его поднимает)
	$(DC) up -d ws-gateway

.PHONY: gateway-down
gateway-down: ## Stop & remove только ws-gateway
	$(DC) rm -sf ws-gateway

.PHONY: gateway-logs
gateway-logs: ## Tail логи ws-gateway
	$(DC) logs -f ws-gateway

.PHONY: build-workers
build-workers: build-libreoffice build-ffmpeg build-image build-data build-metrics-exporter build-gateway ## Build all on-server worker images (AI worker is remote — see build-ai-base / build-ai-cuda / build-ai-cpu)

# Пересоздать ТОЛЬКО контейнеры воркеров из свежих :latest-образов, не трогая
# php/nginx/keydb/mariadb/ws-gateway. --no-deps — чтобы up не поднимал зависимости.
.PHONY: workers-recreate
workers-recreate: ## Recreate только worker-контейнеры (не трогает php/nginx/gateway/keydb/mariadb)
	$(DC) up -d --no-deps worker-libreoffice worker-ffmpeg-audio worker-ffmpeg-video worker-image worker-data

.PHONY: build-data-test
build-data-test: build-data ## Build worker-data:test (test deps pre-baked, не попадает в :latest)
	$(call build_test_img,worker-data)

.PHONY: build-ffmpeg-test
build-ffmpeg-test: build-ffmpeg ## Build worker-ffmpeg:test (test deps pre-baked)
	$(call build_test_img,worker-ffmpeg)

.PHONY: build-image-test
build-image-test: build-image ## Build worker-image:test (test deps pre-baked)
	$(call build_test_img,worker-image)

.PHONY: build-libreoffice-test
build-libreoffice-test: build-libreoffice ## Build worker-libreoffice:test (test deps pre-baked)
	$(call build_test_img,worker-libreoffice)

.PHONY: build-metrics-test
build-metrics-test: build-metrics-exporter ## Build metrics-exporter:test (test deps pre-baked)
	$(call build_test_img,metrics-exporter)

.PHONY: build-workers-test
build-workers-test: build-data-test build-ffmpeg-test build-image-test build-libreoffice-test build-metrics-test ## Build all worker :test images (test deps pre-baked)

##@ Build worker-ai (remote / Harbor + local GPU)

# New two-layer scheme:
#   ai-base  (light: code + pure-python deps) → build-ai-cpu/build-ai-cuda ВСЕГДА
#            пересобирают ai-base ЛОКАЛЬНО (AI_BASE_LOCAL) перед сборкой рабочего
#            образа — гарантирует, что working-образ никогда не подтянет протухший
#            Harbor-тег. push-ai-base — отдельный шаг, только для раздачи базы на
#            другие хосты (remote-воркеры тянут AI_BASE_IMAGE из Harbor).
#   ai.cuda  (FROM ai-base + CUDA ML stack)   → LOCAL build on GPU host, NOT published.
#   ai.cpu   (FROM ai-base + CPU ML stack)    → LOCAL build for CI / CPU-only runs.
#
# Run instructions and model-sharing volumes: see docker-compose.worker-ai.yml header.
HARBOR_NS       ?= harbor.xakki.ru/convertor
AI_BASE_IMAGE   ?= $(HARBOR_NS)/worker-ai-base:latest
AI_BASE_LOCAL   ?= worker-ai-base:local
AI_CUDA_IMAGE   ?= $(IMG)/worker-ai:cuda
AI_CPU_IMAGE    ?= $(IMG)/worker-ai:cpu

# Compute capability as integer (e.g. 86 for RTX 3080/3090).
# Run: nvidia-smi --query-gpu=compute_cap --format=csv,noheader
# Then strip the dot: "8.6" → 86.
CUDA_ARCH       ?= 86
# Dotted form for TORCH_CUDA_ARCH_LIST (derived from CUDA_ARCH).
TORCH_CUDA_ARCH ?= $(shell echo $(CUDA_ARCH) | sed 's/\([0-9]*\)\([0-9]\)/\1.\2/')
# Set to 1 to compile and embed llama-cpp-python with CUDA (larger image, slower build).
# Default 0: Ollama backend works without it; the code path is always present (lazy import).
WITH_LLAMACPP   ?= 0
# App user UID/GID baked into the working images. Default 1000 (host-matchable, so
# bind mounts + the /data volume stay writable). Override to match the build host:
#   make build-ai-cuda APP_UID=$(id -u) APP_GID=$(id -g)
APP_UID         ?= 1000
APP_GID         ?= 1000

.PHONY: build-ai-base
build-ai-base: ## Build the LIGHT ai-base image (code + pure-python deps, no ML); тегирует Harbor-тег + локальный
	docker build -t $(AI_BASE_IMAGE) -t $(AI_BASE_LOCAL) \
	    -f docker/workers/ai-base.Dockerfile .

.PHONY: push-ai-base
push-ai-base: build-ai-base ## Раздать ai-base на другие хосты: push $(AI_BASE_IMAGE) в Harbor (build-ai-cpu/cuda сами по себе от push не зависят)
	docker push $(AI_BASE_IMAGE)

.PHONY: build-ai-cuda
build-ai-cuda: build-ai-base ## LOCAL build: свежий ai-base + ai.cuda (FROM ai-base + CUDA ML). CUDA_ARCH=86 WITH_LLAMACPP=0|1
	docker build -t $(AI_CUDA_IMAGE) \
	    --build-arg AI_BASE_IMAGE=$(AI_BASE_LOCAL) \
	    --build-arg CUDA_ARCH=$(CUDA_ARCH) \
	    --build-arg TORCH_CUDA_ARCH=$(TORCH_CUDA_ARCH) \
	    --build-arg WITH_LLAMACPP=$(WITH_LLAMACPP) \
	    --build-arg APP_UID=$(APP_UID) \
	    --build-arg APP_GID=$(APP_GID) \
	    -f docker/workers/ai.cuda.Dockerfile .

.PHONY: build-ai-cpu
build-ai-cpu: build-ai-base ## LOCAL build: свежий ai-base + ai.cpu (FROM ai-base + CPU ML stack, no GPU required)
	docker build -t $(AI_CPU_IMAGE) \
	    --build-arg AI_BASE_IMAGE=$(AI_BASE_LOCAL) \
	    --build-arg APP_UID=$(APP_UID) \
	    --build-arg APP_GID=$(APP_GID) \
	    -f docker/workers/ai.cpu.Dockerfile .

.PHONY: worker-ai-check
worker-ai-check: ## Validate docker-compose.worker-ai.yml (standalone home compose)
	docker compose -f docker-compose.worker-ai.yml config -q

# On-server run of the AI worker (CPU dev-server) as part of the convertor stack,
# reachable via nginx at /worker-ai/. Explicit -f because the root Makefile
# unexports COMPOSE_FILE and `docker compose` only auto-loads .env (not .env.local),
# so the worker-ai overlay would otherwise be dropped. Image/env come from the
# exported vars (set DOCKER_IMAGE_AI=...:cpu + DEVSERVER_HOST=0.0.0.0 in .env.local).
# Full 4-file set matches the live deployment's COMPOSE_FILE (.env.local): base +
# worker-ai + fluent-logging + limits. worker-ai's own logging/limits are inline in
# docker-compose.worker-ai.yml; the two overlays are included so the file set is
# identical to the running container's and `up` never recreates it with a narrower set.
WORKER_AI_DC = $(DC) -f docker-compose.yml -f docker-compose.worker-ai.yml -f docker/fluent-logging.yml -f docker/limits.yml

.PHONY: worker-ai-up
worker-ai-up: ## Run the AI worker (CPU dev-server) on this host, reachable via nginx /worker-ai/
	$(WORKER_AI_DC) up -d worker-ai

.PHONY: worker-ai-recreate
worker-ai-recreate: ## Force-recreate ONLY worker-ai to pick up a rebuilt image (keeps its overlays)
	$(WORKER_AI_DC) up -d --force-recreate --no-deps worker-ai

.PHONY: worker-ai-down
worker-ai-down: ## Stop & remove the on-server AI worker
	$(WORKER_AI_DC) rm -sf worker-ai

.PHONY: worker-ai-logs
worker-ai-logs: ## Tail the on-server AI worker logs
	$(WORKER_AI_DC) logs -f worker-ai

##@ Queue / Workers

.PHONY: worker-logs
worker-logs: ## Tail logs for on-server worker services (worker-ai is remote — see docker-compose.worker-ai.yml)
	$(DC) logs -f worker-libreoffice worker-ffmpeg worker-image worker-data

.PHONY: queue-status
queue-status: ## Show queue lengths in KeyDB (db 2)
	@echo "=== Queue lengths (KeyDB db 2) ==="
	@docker exec $(KEYDB_CONT) keydb-cli -a "$(REDIS_PASSWORD)" -n $(REDIS_QUEUE_DB) \
	    eval "local ks=redis.call('keys','queue:*') local out={} for _,k in ipairs(ks) do out[#out+1]=k..': '..redis.call('llen',k) end return out" 0

##@ Worker tests

# Per-worker unit runs: each worker's tests execute inside the pre-baked :test
# image (test deps baked in — no pip install at runtime).
# Source is bind-mounted read-only; PYTHONPATH=/src brings in the working tree.
# $(1)=image  $(2)=test files
define RUN_PYTEST_TEST
docker run --rm \
	-v "$(CURDIR):/src:ro" -w /src -e PYTHONPATH=/src -e HOME=/tmp -u root $(1) \
	python3 -m pytest $(2) -m 'not e2e' -p no:cacheprovider -ra -v
endef

.PHONY: test-python
test-python: test-python-data test-python-ffmpeg test-python-image test-python-libreoffice test-python-metrics test-python-ai ## Run the unit suite per-worker INSIDE each worker image (excludes e2e + routing-drift; see test-e2e / test-drift)

.PHONY: test-python-data
test-python-data: build-data-test ## Unit tests for worker-data (+ shared common: stream_consumer, envelope, safe_path) + WS transport
	$(call RUN_PYTEST_TEST,$(COMPOSE_PROJECT_NAME)/worker-data:test,workers/tests/test_data_worker.py workers/tests/test_stream_consumer.py workers/tests/test_envelope_golden.py workers/tests/test_safe_path.py workers/tests/test_data_ws_transport.py)

.PHONY: test-python-ffmpeg
test-python-ffmpeg: build-ffmpeg-test ## Unit + integration tests for worker-ffmpeg (real ffmpeg binary) + WS transport (dual-connection assert)
	$(call RUN_PYTEST_TEST,$(COMPOSE_PROJECT_NAME)/worker-ffmpeg:test,workers/tests/test_ffmpeg_worker.py workers/tests/test_ffmpeg_integration.py workers/tests/test_ffmpeg_ws_transport.py)

.PHONY: test-python-image
test-python-image: build-image-test ## Unit + OCR-integration tests for worker-image (+ cross-worker unsupported-format matrix) + WS transport
	$(call RUN_PYTEST_TEST,$(COMPOSE_PROJECT_NAME)/worker-image:test,workers/tests/test_image_worker_ocr.py workers/tests/test_image_worker_stream.py workers/tests/test_image_worker_ocr_integration.py workers/tests/test_unsupported_formats.py workers/tests/test_image_ws_transport.py)

.PHONY: test-python-libreoffice
test-python-libreoffice: build-libreoffice-test ## Unit + integration tests for worker-libreoffice (real soffice/pandoc/pdftotext) + WS transport
	$(call RUN_PYTEST_TEST,$(COMPOSE_PROJECT_NAME)/worker-libreoffice:test,workers/tests/test_libreoffice_worker.py workers/tests/test_libreoffice_integration.py workers/tests/test_libreoffice_ws_transport.py)

.PHONY: test-python-metrics
test-python-metrics: build-metrics-test ## Unit tests for metrics-exporter (needs prometheus_client — only in its image)
	$(call RUN_PYTEST_TEST,$(COMPOSE_PROJECT_NAME)/metrics-exporter:test,workers/tests/test_metrics_exporter.py)

# AI worker is remote/Harbor-built; its image isn't built on-server. Its unit
# tests need httpx + the dev-server stack (worker.py / devserver import the heavy
# STT/TTS libs lazily). Runs in the pre-baked worker-ffmpeg:test image — test deps
# (fastapi, uvicorn, python-multipart, webrtcvad-wheels, av, numpy, websockets)
# are baked in via workers/requirements-test.txt.
.PHONY: test-python-ai
test-python-ai: build-ffmpeg-test ## AI-worker + dev-server unit tests (runs in worker-ffmpeg:test image, no Harbor pull)
	$(call RUN_PYTEST_TEST,$(COMPOSE_PROJECT_NAME)/worker-ffmpeg:test,workers/tests/test_ai_worker.py workers/tests/test_ai_devserver.py)

# test-drift запускается напрямую на хосте (не в контейнере): проверяет только
# Python-словарь CAPABILITIES vs PHP-ключи реестра — нужен лишь src/ и stdlib.
.PHONY: test-drift
test-drift: ## Routing-contract: PHP registry routing keys vs Python worker CAPABILITIES (may fail on known gaps — see .claude/kanban/grooming/)
	PYTHONPATH=. pytest workers/tests/test_routing_drift.py -v

# REDIS_DB=3 — та же изоляция, что test-e2e (.env.test: db3 отделяет
# тест-стримы/ключи от прод-db2). Без этого gateway-тесты, использующие
# load_config() напрямую (test_gateway_keydb / _reclaim_dlq / _progress_status),
# писали XADD'ы (в т.ч. в canonical conv.dead) прямиком в dev-db2 (hardening-04).
.PHONY: test-gateway
test-gateway: build-data-test ## WS-Gateway KeyDB-слой на РЕАЛЬНОМ keydb (async, pytest-asyncio)
	$(COMPOSE_TEST) up -d --wait keydb
	docker run --rm -u root \
	    --network $(COMPOSE_PROJECT_NAME)-network \
	    -e REDIS_HOST=$(KEYDB_CONT) \
	    -e REDIS_DB=3 \
	    -v "$(CURDIR):/src:ro" -w /src -e PYTHONPATH=/src -e HOME=/tmp \
	    $(COMPOSE_PROJECT_NAME)/worker-data:test \
	    python3 -m pytest workers/tests/test_gateway_keydb.py workers/tests/test_gateway_ws.py workers/tests/test_gateway_relay.py workers/tests/test_gateway_liveness.py workers/tests/test_gateway_reclaim_dlq.py workers/tests/test_gateway_dlq_consumer.py workers/tests/test_gateway_progress_status.py workers/tests/test_ws_client.py workers/tests/test_ws_transport_integration.py -p no:cacheprovider -ra -v

.PHONY: test-e2e
test-e2e: build-workers build-ffmpeg-test ## Real S3 in/out e2e for ffmpeg + data workers (run `make up` first)
	$(E2E_CLEAN_ENV) $(COMPOSE_TEST) up -d --force-recreate --no-deps --wait php nginx ws-gateway worker-ffmpeg-audio worker-ffmpeg-video worker-data
	$(COMPOSE_TEST) exec -T php bin/console doctrine:migrations:migrate --no-interaction
	docker run --rm -u root \
	    --network $(COMPOSE_PROJECT_NAME)-network \
	    -e REDIS_HOST=$(KEYDB_CONT) \
	    -e REDIS_DB=3 \
	    -e REDIS_PASSWORD -e S3_ENDPOINT -e S3_KEY -e S3_SECRET \
	    -e S3_BUCKET_PREFIX -e S3_REGION -e S3_USE_PATH_STYLE \
	    -e S3_PREFIX=test_ \
	    -e DB_HOST=$(DB_HOST) -e DB_NAME=convertor-test -e DB_USER=convertor-test -e DB_PASS=$(DB_TEST_PASS) \
	    -v "$(CURDIR):/src:ro" -w /src -e PYTHONPATH=/src -e HOME=/tmp \
	    $(COMPOSE_PROJECT_NAME)/worker-ffmpeg:test \
	    python3 -m pytest workers/tests/test_workers_e2e.py -m e2e -p no:cacheprovider -ra -v; \
	    rc=$$?; \
	    echo "[test-e2e] restoring workers to base config…"; \
	    $(DC) up -d --force-recreate --no-deps php nginx ws-gateway worker-ffmpeg-audio worker-ffmpeg-video worker-data >/dev/null; \
	    exit $$rc

# Public-API integration e2e (PHPUnit, group=integration): drives POST /convert →
# poll status → download over REAL HTTP against the in-compose stack, one case per
# category (image/data/ffmpeg/document). Brings up the full worker set under
# .env.test (APP_ENV=test, test DB, KeyDB db3, S3 test_ prefix) via the same
# E2E_CLEAN_ENV + COMPOSE_TEST recipe as test-e2e, migrates the test DB, runs
# phpunit inside the php container, then restores the stack to base (dev) config.
# Worker images must already exist — run `make up` (or `make build-workers`) first.
.PHONY: test-api-integration
test-api-integration: ## Public-API e2e integration tests (PHPUnit group=integration): image/data/ffmpeg/document — run `make up` first
	$(E2E_CLEAN_ENV) $(COMPOSE_TEST) up -d --force-recreate --no-deps --wait php nginx ws-gateway worker-image worker-libreoffice worker-ffmpeg-audio worker-ffmpeg-video worker-data
	$(COMPOSE_TEST) exec -T php bin/console doctrine:migrations:migrate --no-interaction
	$(COMPOSE_TEST) exec -T php php vendor/bin/phpunit --group integration; \
	    rc=$$?; \
	    echo "[test-api-integration] restoring workers to base config…"; \
	    $(DC) up -d --force-recreate --no-deps php nginx ws-gateway worker-image worker-libreoffice worker-ffmpeg-audio worker-ffmpeg-video worker-data >/dev/null; \
	    exit $$rc
