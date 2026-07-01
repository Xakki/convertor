## Don't use alone — included into the parent Makefile

# On-server worker image name prefix (COMPOSE_PROJECT_NAME from the root .env).
IMG = $(COMPOSE_PROJECT_NAME)

# Build one on-server worker image. $(1)=image stem (the /<stem>:latest tag),
# $(2)=dockerfile stem (docker/workers/<stem>.Dockerfile). Most workers share the
# stem; metrics-exporter does NOT (tag has no `worker-` prefix, dockerfile is
# metrics_exporter.Dockerfile) — hence two params.
define build_img
docker build -t $(IMG)/$(1):latest -f docker/workers/$(2).Dockerfile .
endef

##@ Build individual worker images

.PHONY: build-libreoffice
build-libreoffice: ## Build worker-libreoffice image
	$(call build_img,worker-libreoffice,libreoffice)

.PHONY: build-ffmpeg
build-ffmpeg: ## Build worker-ffmpeg image
	$(call build_img,worker-ffmpeg,ffmpeg)

.PHONY: build-image
build-image: ## Build worker-image image
	$(call build_img,worker-image,image)

.PHONY: build-data
build-data: ## Build worker-data image
	$(call build_img,worker-data,data)

.PHONY: build-metrics-exporter
build-metrics-exporter: ## Build metrics-exporter image
	$(call build_img,metrics-exporter,metrics_exporter)

.PHONY: build-workers
build-workers: build-libreoffice build-ffmpeg build-image build-data build-metrics-exporter ## Build all on-server worker images (AI worker is remote — see build-ai-base / build-ai-cuda / build-ai-cpu)

##@ Build worker-ai (remote / Harbor + local GPU)

# New two-layer scheme:
#   ai-base  (light: code + pure-python deps) → published to Harbor, rarely rebuilt.
#   ai.cuda  (FROM ai-base + CUDA ML stack)   → LOCAL build on GPU host, NOT published.
#   ai.cpu   (FROM ai-base + CPU ML stack)    → LOCAL build for CI / CPU-only runs.
#
# Run instructions and model-sharing volumes: see docker-compose.worker-ai.yml header.
HARBOR_NS       ?= harbor.xakki.ru/convertor
AI_BASE_IMAGE   ?= $(HARBOR_NS)/worker-ai-base:latest
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
build-ai-base: ## Build the LIGHT ai-base image (code + pure-python deps, no ML)
	docker build -t $(AI_BASE_IMAGE) \
	    -f docker/workers/ai-base.Dockerfile .

.PHONY: push-ai-base
push-ai-base: build-ai-base ## Build + push ai-base to Harbor (the only published AI image)
	docker push $(AI_BASE_IMAGE)

.PHONY: build-ai-cuda
build-ai-cuda: ## LOCAL build: ai.cuda (FROM ai-base + CUDA ML). CUDA_ARCH=86 WITH_LLAMACPP=0|1
	docker build -t $(AI_CUDA_IMAGE) \
	    --build-arg AI_BASE_IMAGE=$(AI_BASE_IMAGE) \
	    --build-arg CUDA_ARCH=$(CUDA_ARCH) \
	    --build-arg TORCH_CUDA_ARCH=$(TORCH_CUDA_ARCH) \
	    --build-arg WITH_LLAMACPP=$(WITH_LLAMACPP) \
	    --build-arg APP_UID=$(APP_UID) \
	    --build-arg APP_GID=$(APP_GID) \
	    -f docker/workers/ai.cuda.Dockerfile .

.PHONY: build-ai-cpu
build-ai-cpu: ## LOCAL build: ai.cpu (FROM ai-base + CPU ML stack, no GPU required)
	docker build -t $(AI_CPU_IMAGE) \
	    --build-arg AI_BASE_IMAGE=$(AI_BASE_IMAGE) \
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
WORKER_AI_DC = $(DC) -f docker-compose.yml -f docker-compose.worker-ai.yml

.PHONY: worker-ai-up
worker-ai-up: ## Run the AI worker (CPU dev-server) on this host, reachable via nginx /worker-ai/
	$(WORKER_AI_DC) up -d worker-ai

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

# Per-worker unit runs: each worker's tests execute inside that worker's own
# image (interpreter + pinned deps + system binaries). Source is bind-mounted
# read-only and PYTHONPATH=/src, so code comes from the working tree; the image
# only supplies deps. pytest(+asyncio) is installed at runtime (mirrors
# test-image-ocr). `-m "not e2e"` keeps the same selection as the old host run;
# integration tests now run for real (the binary IS present in the worker image).
# $(1)=image  $(2)=test files  $(3)=extra pip pkgs
define RUN_PYTEST_IN
docker run --rm --entrypoint sh \
	-v "$(CURDIR):/src:ro" -w /src -e PYTHONPATH=/src -e HOME=/tmp -u root $(1) \
	-c "pip install --no-cache-dir --quiet pytest pytest-asyncio $(3) && python3 -m pytest $(2) -m 'not e2e' -p no:cacheprovider -ra -v"
endef

.PHONY: test-python
test-python: test-python-data test-python-ffmpeg test-python-image test-python-libreoffice test-python-metrics test-python-ai ## Run the unit suite per-worker INSIDE each worker image (excludes e2e + routing-drift; see test-e2e / test-drift)

.PHONY: test-python-data
test-python-data: build-data ## Unit tests for worker-data (+ shared common: stream_consumer, safe_path)
	$(call RUN_PYTEST_IN,$(COMPOSE_PROJECT_NAME)/worker-data:latest,workers/tests/test_data_worker.py workers/tests/test_stream_consumer.py workers/tests/test_safe_path.py,)

.PHONY: test-python-ffmpeg
test-python-ffmpeg: build-ffmpeg ## Unit + integration tests for worker-ffmpeg (real ffmpeg binary)
	$(call RUN_PYTEST_IN,$(COMPOSE_PROJECT_NAME)/worker-ffmpeg:latest,workers/tests/test_ffmpeg_worker.py workers/tests/test_ffmpeg_integration.py,)

.PHONY: test-python-image
test-python-image: build-image ## Unit + OCR-integration tests for worker-image (+ cross-worker unsupported-format matrix)
	$(call RUN_PYTEST_IN,$(COMPOSE_PROJECT_NAME)/worker-image:latest,workers/tests/test_image_worker_ocr.py workers/tests/test_image_worker_stream.py workers/tests/test_image_worker_ocr_integration.py workers/tests/test_unsupported_formats.py,)

.PHONY: test-python-libreoffice
test-python-libreoffice: build-libreoffice ## Unit + integration tests for worker-libreoffice (real soffice/pandoc/pdftotext)
	$(call RUN_PYTEST_IN,$(COMPOSE_PROJECT_NAME)/worker-libreoffice:latest,workers/tests/test_libreoffice_worker.py workers/tests/test_libreoffice_integration.py,)

.PHONY: test-python-metrics
test-python-metrics: build-metrics-exporter ## Unit tests for metrics-exporter (needs prometheus_client — only in its image)
	$(call RUN_PYTEST_IN,$(COMPOSE_PROJECT_NAME)/metrics-exporter:latest,workers/tests/test_metrics_exporter.py,)

# AI worker is remote/Harbor-built; its image isn't built on-server. Its unit
# tests need httpx + the dev-server stack (worker.py / devserver import the heavy
# STT/TTS libs lazily), so we run them in the lean worker-ffmpeg image with those
# deps pip-installed at runtime. FastAPI's TestClient covers the WS route
# in-process; python-multipart is required for the /api/run multipart upload test.
.PHONY: test-python-ai
test-python-ai: build-ffmpeg ## AI-worker + dev-server unit tests (runs in worker-ffmpeg image, no Harbor pull)
	$(call RUN_PYTEST_IN,$(COMPOSE_PROJECT_NAME)/worker-ffmpeg:latest,workers/tests/test_ai_worker.py workers/tests/test_ai_devserver.py,httpx fastapi uvicorn[standard] python-multipart)

.PHONY: test-drift
test-drift: ## Routing-contract: PHP registry routing keys vs Python worker CAPABILITIES (may fail on known gaps — see .claude/kanban/grooming/)
	PYTHONPATH=. pytest workers/tests/test_routing_drift.py -v

.PHONY: test-e2e
test-e2e: ## Real S3 in/out e2e for ffmpeg + data workers (run `make up` first)
	$(COMPOSE_TEST) up -d --force-recreate --no-deps worker-ffmpeg worker-data
	$(COMPOSE_TEST) run --rm --no-deps --user $(PUID):$(PGID) \
	    -v "$(CURDIR):/src:ro" -w /src -e PYTHONPATH=/src -e HOME=/tmp \
	    --entrypoint sh worker-ffmpeg \
	    -c "pip install --no-cache-dir --quiet --user pytest && \
	        python3 -m pytest workers/tests/test_workers_e2e.py -m e2e -p no:cacheprovider -ra -v"; \
	    rc=$$?; \
	    echo "[test-e2e] restoring workers to base config…"; \
	    $(DC) up -d --force-recreate --no-deps worker-ffmpeg worker-data >/dev/null; \
	    exit $$rc
