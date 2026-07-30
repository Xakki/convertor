## Don't use alone — included into the parent Makefile

# Namespace образов воркеров (IMAGE_NS из .env; НЕ зависит от стенда — dev и test
# используют одни и те же образы).
IMG = $(IMAGE_NS)

# Worker build-версия: полная version = APP_VER (.env) + монотонный build-счётчик из
# gitignored `.i` в корне репо → напр. "0.1.6". Запекается в образ (ARG→ENV APP_VER +
# /app/.i); воркер РЕПОРТИТ её в `ready` (транспорт-only, gateway не потребляет).
.PHONY: bump-i
bump-i: ## Инкремент build-счётчика .i (создаёт при отсутствии)
	@n=$$(cat $(CURDIR)/.i 2>/dev/null || echo 0); n=$$((n+1)); printf '%s' "$$n" > $(CURDIR)/.i; echo "build counter .i → $$n"

# Build one on-server worker image. $(1)=image stem (тег /<stem>:latest),
# $(2)=dockerfile stem (docker/workers/<stem>.Dockerfile). У большинства воркеров
# stem общий; у metrics-exporter — нет, отсюда два параметра.
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
push-gateway: build-gateway ## Build + push ws-gateway в Harbor (нужен `make harbor-login`)
	docker tag $(IMG)/ws-gateway:latest $(HARBOR_NS)/ws-gateway:latest
	docker push $(HARBOR_NS)/ws-gateway:latest

.PHONY: gateway-up
gateway-up: ## Start только ws-gateway (глобальный `make up` тоже его поднимает)
	$(DC) up -d ws-gateway

.PHONY: gateway-down
gateway-down: ## Stop & remove только ws-gateway
	$(DC) rm -sf ws-gateway

.PHONY: gateway-recreate
gateway-recreate: ## Force-recreate ТОЛЬКО ws-gateway на свежий образ, --no-deps (иначе depends_on тянет keydb)
	$(DC) up -d --force-recreate --no-deps ws-gateway

.PHONY: gateway-logs
gateway-logs: ## Tail логи ws-gateway
	$(DC) logs -f ws-gateway

.PHONY: build-workers
build-workers: build-libreoffice build-ffmpeg build-image build-data build-metrics-exporter build-gateway ## Build все on-server образы КРОМЕ worker-ai (у него своя двухступенчатая сборка: build-ai-cpu / build-ai-cuda)

# Пересоздать ТОЛЬКО контейнеры воркеров из свежих образов, не трогая
# php/nginx/keydb/mariadb/ws-gateway. --no-deps — чтобы up не поднимал зависимости.
.PHONY: workers-recreate
workers-recreate: ## Recreate все 6 worker-контейнеров, вкл. worker-ai (не трогает php/nginx/gateway/keydb/mariadb)
	$(DC) up -d --no-deps worker-libreoffice worker-ffmpeg-audio worker-ffmpeg-video worker-image worker-data worker-ai

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

# Двухслойная схема:
#   ai-base  (light: код + pure-python deps) → build-ai-cpu/build-ai-cuda ВСЕГДА
#            пересобирают ai-base ЛОКАЛЬНО (AI_BASE_LOCAL) перед сборкой рабочего
#            образа: working-образ никогда не подтянет протухший Harbor-тег.
#            push-ai-base — отдельный шаг, только для раздачи базы на другие хосты.
#   ai.cuda  (FROM ai-base + CUDA ML stack)   → LOCAL build на GPU-хосте, не публикуется.
#   ai.cpu   (FROM ai-base + CPU ML stack)    → LOCAL build для CI / CPU-only.
HARBOR_NS       ?= harbor.xakki.ru/convertor
AI_BASE_IMAGE   ?= $(HARBOR_NS)/worker-ai-base:latest
AI_BASE_LOCAL   ?= worker-ai-base:local
AI_CUDA_IMAGE   ?= $(IMG)/worker-ai:cuda
AI_CPU_IMAGE    ?= $(IMG)/worker-ai:cpu

# Compute capability целым числом (86 для RTX 3080/3090):
#   nvidia-smi --query-gpu=compute_cap --format=csv,noheader → "8.6" → 86.
CUDA_ARCH       ?= 86
TORCH_CUDA_ARCH ?= $(shell echo $(CUDA_ARCH) | sed 's/\([0-9]*\)\([0-9]\)/\1.\2/')
# 1 = вкомпилировать llama-cpp-python с CUDA (образ больше, сборка дольше).
# 0 (дефолт): backend Ollama работает без него, код-путь всегда есть (lazy import).
WITH_LLAMACPP   ?= 0
# UID/GID приложения, запекаемые в рабочие образы. 1000 = host-matchable, чтобы
# bind-mount'ы и том /data оставались записываемыми:
#   make build-ai-cuda APP_UID=$(id -u) APP_GID=$(id -g)
APP_UID         ?= 1000
APP_GID         ?= 1000

.PHONY: build-ai-base
build-ai-base: ## Build LIGHT ai-base (код + pure-python deps, без ML); тегирует Harbor-тег + локальный
	docker build -t $(AI_BASE_IMAGE) -t $(AI_BASE_LOCAL) \
	    -f docker/workers/ai-base.Dockerfile .

.PHONY: push-ai-base
push-ai-base: build-ai-base ## Раздать ai-base на другие хосты: push в Harbor (build-ai-cpu/cuda от push не зависят)
	docker push $(AI_BASE_IMAGE)

.PHONY: build-ai-cuda
build-ai-cuda: build-ai-base ## LOCAL build: свежий ai-base + ai.cuda (CUDA ML). CUDA_ARCH=86 WITH_LLAMACPP=0|1
	docker build -t $(AI_CUDA_IMAGE) \
	    --build-arg AI_BASE_IMAGE=$(AI_BASE_LOCAL) \
	    --build-arg CUDA_ARCH=$(CUDA_ARCH) \
	    --build-arg TORCH_CUDA_ARCH=$(TORCH_CUDA_ARCH) \
	    --build-arg WITH_LLAMACPP=$(WITH_LLAMACPP) \
	    --build-arg APP_UID=$(APP_UID) \
	    --build-arg APP_GID=$(APP_GID) \
	    -f docker/workers/ai.cuda.Dockerfile .

.PHONY: build-ai-cpu
build-ai-cpu: build-ai-base ## LOCAL build: свежий ai-base + ai.cpu (CPU ML stack, GPU не нужен)
	docker build -t $(AI_CPU_IMAGE) \
	    --build-arg AI_BASE_IMAGE=$(AI_BASE_LOCAL) \
	    --build-arg APP_UID=$(APP_UID) \
	    --build-arg APP_GID=$(APP_GID) \
	    -f docker/workers/ai.cpu.Dockerfile .

##@ Queue / Workers

.PHONY: worker-logs
worker-logs: ## Tail logs всех 6 on-server воркеров вкл. worker-ai
	$(DC) logs -f worker-libreoffice worker-ffmpeg-audio worker-ffmpeg-video worker-image worker-data worker-ai

# Свой fluent-bit нужен ТОЛЬКО remote-хосту (на главном сервере логи шлём в общий
# host-wide сборщик на ${EXT_FLUENT_PORT}, и порт уже занят им).
.PHONY: fluent-up
fluent-up: ## REMOTE-хост: поднять свой fluent-bit-сайдкар (на главном сервере не нужен — там общий)
	$(DC_FLUENT) up -d fluent-bit

.PHONY: fluent-restart
fluent-restart: ## Restart fluent-bit-сайдкара (подхватить изменения conf.d)
	$(DC_FLUENT) restart fluent-bit

.PHONY: fluent-logs
fluent-logs: ## Tail логи fluent-bit-сайдкара
	$(DC_FLUENT) logs -f fluent-bit

.PHONY: queue-status
queue-status: ## Показать длины очередей в KeyDB (индекс БД = REDIS_QUEUE_DB)
	@docker exec $(KEYDB_CONT) keydb-cli -a "$(REDIS_PASSWORD)" -n $(REDIS_QUEUE_DB) \
	    eval "local ks=redis.call('keys','queue:*') local out={} for _,k in ipairs(ks) do out[#out+1]=k..': '..redis.call('llen',k) end return out" 0

##@ Worker tests

# Per-worker unit runs: тесты каждого воркера идут внутри pre-baked :test образа
# (test-deps запечены — никакого pip install в runtime). Стенд НЕ нужен.
# Исходники bind-mount'ятся read-only; PYTHONPATH=/src подключает рабочее дерево.
# $(1)=image  $(2)=test files
define RUN_PYTEST_TEST
docker run --rm \
	-v "$(CURDIR):/src:ro" -w /src -e PYTHONPATH=/src -e HOME=/tmp -u root $(1) \
	python3 -m pytest $(2) -m 'not e2e' -p no:cacheprovider -ra -v
endef

.PHONY: test-python
test-python: test-python-data test-python-ffmpeg test-python-image test-python-libreoffice test-python-metrics test-python-ai ## Unit-сюита по воркерам ВНУТРИ их образов (без e2e и drift; стенд не нужен)

.PHONY: test-python-data
test-python-data: build-data-test ## Unit тесты worker-data (+ shared common: stream_consumer, envelope, safe_path) + WS transport
	$(call RUN_PYTEST_TEST,$(IMG)/worker-data:test,workers/tests/test_data_worker.py workers/tests/test_stream_consumer.py workers/tests/test_envelope_golden.py workers/tests/test_safe_path.py workers/tests/test_data_ws_transport.py)

.PHONY: test-python-ffmpeg
test-python-ffmpeg: build-ffmpeg-test ## Unit + integration тесты worker-ffmpeg (реальный ffmpeg) + WS transport
	$(call RUN_PYTEST_TEST,$(IMG)/worker-ffmpeg:test,workers/tests/test_ffmpeg_worker.py workers/tests/test_ffmpeg_integration.py workers/tests/test_ffmpeg_ws_transport.py)

.PHONY: test-python-image
test-python-image: build-image-test ## Unit + OCR-integration тесты worker-image (+ unsupported-format матрица) + WS transport
	$(call RUN_PYTEST_TEST,$(IMG)/worker-image:test,workers/tests/test_image_worker_ocr.py workers/tests/test_image_worker_stream.py workers/tests/test_image_worker_ocr_integration.py workers/tests/test_unsupported_formats.py workers/tests/test_image_ws_transport.py)

.PHONY: test-python-libreoffice
test-python-libreoffice: build-libreoffice-test ## Unit + integration тесты worker-libreoffice (реальные soffice/pandoc/pdftotext) + WS transport
	$(call RUN_PYTEST_TEST,$(IMG)/worker-libreoffice:test,workers/tests/test_libreoffice_worker.py workers/tests/test_libreoffice_integration.py workers/tests/test_libreoffice_ws_transport.py)

.PHONY: test-python-metrics
test-python-metrics: build-metrics-test ## Unit тесты metrics-exporter (prometheus_client есть только в его образе)
	$(call RUN_PYTEST_TEST,$(IMG)/metrics-exporter:test,workers/tests/test_metrics_exporter.py)

# У worker-ai своего :test образа нет (build-ai-cpu/-cuda его не пекут) — тесты
# гоняются в worker-ffmpeg:test, где уже запечены нужные deps (fastapi, uvicorn,
# python-multipart, webrtcvad-wheels, av, numpy, websockets из requirements-test.txt).
.PHONY: test-python-ai
test-python-ai: build-ffmpeg-test ## Unit тесты AI-воркера + dev-server (в образе worker-ffmpeg:test, без Harbor-пулла)
	$(call RUN_PYTEST_TEST,$(IMG)/worker-ffmpeg:test,workers/tests/test_ai_worker.py workers/tests/test_ai_devserver.py)

# test-drift гоняется на хосте (docker exec в php-контейнер стенда), не только
# src+stdlib: нужен docker + поднятый php + непустая БД (register-round-trip:
# dump-matrix.php --json vs Python CAPABILITIES).
# test_worker_type_drift.py — второй, независимый guard в том же таргете: чистый
# text-parse, сверяет PHP-enum WorkerType с 3 статическими зеркалами
# (ws_client.py, keydb.py, messenger.yaml).
.PHONY: test-drift
test-drift: ## Drift guards: routing (dump-matrix.php vs CAPABILITIES) + worker-type (enum vs 3 зеркала)
	$(REQUIRE_TEST)
	PYTHONPATH=. pytest workers/tests/test_routing_drift.py workers/tests/test_worker_type_drift.py -v

.PHONY: test-gateway
test-gateway: build-data-test ## KeyDB-слой WS-Gateway на РЕАЛЬНОМ keydb тест-стенда (async, pytest-asyncio)
	$(REQUIRE_TEST)
	$(DC) up -d --wait keydb
	docker run --rm -u root \
	    --network $(COMPOSE_PROJECT_NAME)-network \
	    -e REDIS_HOST=$(KEYDB_CONT) -e REDIS_DB=$(REDIS_QUEUE_DB) \
	    -v "$(CURDIR):/src:ro" -w /src -e PYTHONPATH=/src -e HOME=/tmp \
	    $(IMG)/worker-data:test \
	    python3 -m pytest workers/tests/test_gateway_keydb.py workers/tests/test_gateway_ws.py workers/tests/test_gateway_relay.py workers/tests/test_gateway_liveness.py workers/tests/test_gateway_liveness_push.py workers/tests/test_gateway_reclaim_dlq.py workers/tests/test_gateway_dlq_consumer.py workers/tests/test_gateway_progress_status.py workers/tests/test_ws_client.py workers/tests/test_ws_transport_integration.py -p no:cacheprovider -ra -v

.PHONY: test-e2e
test-e2e: build-workers build-ffmpeg-test ## Реальный S3 in/out e2e для ffmpeg + data воркеров на тест-стенде
	$(REQUIRE_TEST)
	$(MAKE) up migrate
	docker run --rm -u root \
	    --network $(COMPOSE_PROJECT_NAME)-network \
	    -e REDIS_HOST=$(KEYDB_CONT) -e REDIS_DB=$(REDIS_QUEUE_DB) -e REDIS_PASSWORD \
	    -e S3_ENDPOINT -e S3_KEY -e S3_SECRET -e S3_BUCKET_PREFIX -e S3_REGION \
	    -e S3_USE_PATH_STYLE -e S3_PREFIX \
	    -e DB_HOST=mariadb -e DB_NAME=$(MARIADB_DATABASE) \
	    -e DB_USER=$(MARIADB_USER) -e DB_PASS=$(MARIADB_PASSWORD) \
	    -v "$(CURDIR):/src:ro" -w /src -e PYTHONPATH=/src -e HOME=/tmp \
	    $(IMG)/worker-ffmpeg:test \
	    python3 -m pytest workers/tests/test_workers_e2e.py -m e2e -p no:cacheprovider -ra -v

# Public-API integration e2e (PHPUnit, group=integration): POST /convert → poll
# status → download по РЕАЛЬНОМУ HTTP против стенда, по одному кейсу на категорию
# (image/data/ffmpeg/document). build-workers — обязательный prereq: стенд поднимает
# воркеры из :latest, и без пересборки тест гонял бы СТАРЫЙ код воркеров.
.PHONY: test-api-integration
test-api-integration: build-workers ## Public-API e2e (PHPUnit group=integration): image/data/ffmpeg/document
	$(REQUIRE_TEST)
	$(MAKE) up migrate
	$(DC) exec -T php php vendor/bin/phpunit --group integration
