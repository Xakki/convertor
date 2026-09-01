## Don't use alone — included into the parent Makefile

# Namespace образов воркеров (IMAGE_NS из .env; НЕ зависит от стенда — dev и test
# используют одни и те же образы).
IMG = $(IMAGE_NS)

# queue-inspect получает STREAM только через окружение рецепта, а не через
# make-подстановку в shell-исходник.
export STREAM

# Публичный релизный тег содержит только APP_VER и latest. Build-счётчик `.i`,
# revision и состояние исходников — отдельная runtime provenance, не часть тега.
RELEASE_TAG := $(APP_VER)
GIT_REVISION := $(shell git rev-parse HEAD)
BUILD_SOURCE_STATE := $(shell if [ -z "$$(git status --porcelain)" ]; then printf clean; else printf dirty; fi)
GATEWAY_GIT_SHA := $(GIT_REVISION)
GATEWAY_SOURCE_STATE := $(BUILD_SOURCE_STATE)

.PHONY: release-guard
release-guard:  # служебный prereq release-workers, не пользовательская команда
	@if [ -n "$$(git status --porcelain)" ]; then \
	    echo "release-guard: dirty working tree — commit or stash before releasing"; \
	    exit 1; \
	fi

# Worker build-версия: полная version = APP_VER (.env) + монотонный build-счётчик из
# gitignored `.i` в корне репо → напр. "0.1.6". Запекается в образ (ARG→ENV APP_VER +
# /app/.i); воркер РЕПОРТИТ её в `ready` (транспорт-only, gateway не потребляет).
.PHONY: bump-i
bump-i:  # служебный prereq build-таргетов, не пользовательская команда
	@n=$$(cat $(CURDIR)/.i 2>/dev/null || echo 0); n=$$((n+1)); printf '%s' "$$n" > $(CURDIR)/.i; echo "build counter .i → $$n"

# Флаги форсированной пересборки — пусто в обычной сборке, "--no-cache --pull" у
# rebuild-workers (задаётся target-specific переменной, наследуется пререквизитами).
DOCKER_BUILD_FLAGS ?=

# Build one on-server worker image. $(1)=image stem (тег /<stem>:latest),
# $(2)=dockerfile stem (docker/workers/<stem>.Dockerfile). У большинства воркеров
# stem общий; у metrics-exporter — нет, отсюда два параметра.
define build_img
docker build $(DOCKER_BUILD_FLAGS) -t $(IMG)/$(1):latest \
    --build-arg APP_VER=$(APP_VER) \
    --build-arg WORKER_BUILD="$$(cat $(CURDIR)/.i 2>/dev/null || echo 0)" \
    --build-arg GIT_REVISION=$(GIT_REVISION) \
    --build-arg SOURCE_STATE=$(BUILD_SOURCE_STATE) \
    --build-arg IMAGE_REPOSITORY=$(IMG)/$(1) \
    -f docker/workers/$(2).Dockerfile .
endef

define build_gateway_img
docker build $(DOCKER_BUILD_FLAGS) -t $(IMG)/ws-gateway:latest \
    --build-arg APP_VER=$(APP_VER) \
    --build-arg WORKER_BUILD="$$(cat $(CURDIR)/.i 2>/dev/null || echo 0)" \
    --build-arg GATEWAY_GIT_SHA=$(GATEWAY_GIT_SHA) \
    --build-arg GATEWAY_SOURCE_STATE=$(GATEWAY_SOURCE_STATE) \
    --build-arg GIT_REVISION=$(GIT_REVISION) \
    --build-arg SOURCE_STATE=$(BUILD_SOURCE_STATE) \
    --build-arg IMAGE_REPOSITORY=$(IMG)/ws-gateway \
    -f docker/workers/gateway.Dockerfile .
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

.PHONY: build-api
build-api: bump-i ## Build worker-api image
	$(call build_img,worker-api,api)

.PHONY: build-api-test
build-api-test: build-api ## Build worker-api:test with test dependencies
	$(call build_test_img,worker-api)

.PHONY: build-metrics-exporter
build-metrics-exporter: bump-i ## Build metrics-exporter image
	$(call build_img,metrics-exporter,metrics_exporter)

.PHONY: build-gateway
build-gateway: bump-i ## Build ws-gateway image (Harbor-suitable)
	$(call build_gateway_img)

.PHONY: gateway-up
gateway-up: validate-ai-image ## Start только ws-gateway (глобальный `make up` тоже его поднимает)
	$(DC) up -d ws-gateway

.PHONY: gateway-down
gateway-down: validate-ai-image ## Stop & remove только ws-gateway
	$(DC) rm -sf ws-gateway

.PHONY: gateway-recreate
gateway-recreate: validate-ai-image ## Force-recreate ТОЛЬКО ws-gateway на свежем образе, --no-deps (иначе depends_on тянет keydb)
	$(DC) up -d --force-recreate --no-deps ws-gateway

.PHONY: metrics-exporter-recreate
metrics-exporter-recreate: validate-ai-image ## Force-recreate metrics-exporter (profile monitoring), --no-deps
	$(DC) up -d --force-recreate --no-deps metrics-exporter

.PHONY: gateway-logs
gateway-logs: ## Tail логи ws-gateway
	$(DC) logs -f ws-gateway

.PHONY: gateway-image-info
gateway-image-info: ## RO: image id + build time образа ws-gateway (свежесть перед/после recreate)
	docker inspect $(IMG)/ws-gateway:$(IMAGE_TAG) --format 'ImageID: {{.Id}} | Created: {{.Created}}'

.PHONY: gateway-verify-sha
gateway-verify-sha: ## RO: сравнить чистый SHA запущенного ws-gateway с локальным HEAD
	@set -eu; \
	local_sha="$$(git rev-parse --short HEAD)"; \
	container_id="$$($(DC) ps -q ws-gateway)"; \
	if [ -z "$$container_id" ]; then \
	    echo "Local HEAD SHA: $$local_sha"; \
	    echo "Container SHA: unavailable (ws-gateway is not running)"; \
	    exit 2; \
	fi; \
	container_sha="$$(docker inspect "$$container_id" --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}')"; \
	if [ -z "$$container_sha" ] || [ "$$container_sha" = "<no value>" ]; then \
	    echo "Local HEAD SHA: $$local_sha"; \
	    echo "Container SHA: unavailable (image has no org.opencontainers.image.revision label)"; \
	    exit 2; \
	fi; \
	container_source_state="$$(docker inspect "$$container_id" --format '{{ index .Config.Labels "org.opencontainers.image.source-state" }}')"; \
	echo "Local HEAD SHA: $$local_sha"; \
	echo "Container SHA: $$container_sha"; \
	if [ -z "$$container_source_state" ] || [ "$$container_source_state" = "<no value>" ]; then \
	    echo "Container source state: unavailable (image has no org.opencontainers.image.source-state label)"; \
	    exit 2; \
	fi; \
	echo "Container source state: $$container_source_state"; \
	if [ "$$container_source_state" != "clean" ]; then \
	    echo "SHA match: no (container source state is $$container_source_state)"; \
	    exit 1; \
	fi; \
	if [ "$$local_sha" = "$$container_sha" ]; then \
	    echo "SHA match: yes"; \
	else \
	    echo "SHA match: no"; \
	    exit 1; \
	fi

.PHONY: gateway-verify-code
gateway-verify-code: ## RO: скопировать dlq_consumer.py ИЗ контейнера ws-gateway (только OUT, никогда cp внутрь bind-mount) и найти DLQ_AUDIT_LOG_MARKER
	@tmp=$$(mktemp); $(DC) cp ws-gateway:/app/workers/gateway/dlq_consumer.py $$tmp && grep -n DLQ_AUDIT_LOG_MARKER $$tmp; rm -f $$tmp

.PHONY: build-workers
build-workers: build-libreoffice build-ffmpeg build-image build-data build-metrics-exporter build-gateway ## REMOTE-safe: build 6 runnable образов без server-only worker-api и worker-ai

.PHONY: build-server-workers
build-server-workers: build-workers build-api ## SERVER: build generic remote-safe images + production-only worker-api

# Эти guard'ы относятся только к операциям основного application Compose-файла.
# Fluent sidecar targets ниже используют отдельный DC_FLUENT и намеренно не
# требуют AI_IMAGE.
# Профиль и allowlist для безопасного точечного recreate. Профиль saVpn намеренно
# ограничен data/image: это единственный документированный способ включить такой
# хост, без чтения или изменения его секретного .env.local.
WORKER_RECREATE_PROFILE ?= remote
WORKER_RECREATE_SERVICES ?=
WORKER_RECREATE_REMOTE_SERVICES := worker-libreoffice worker-ffmpeg-audio worker-ffmpeg-video worker-image worker-data worker-ai
WORKER_RECREATE_LOCAL_SERVICES := $(WORKER_RECREATE_REMOTE_SERVICES)
WORKER_RECREATE_SAVPN_SERVICES := worker-data worker-image

# Пересоздать/пуллить ТОЛЬКО контейнеры из проверенного effective set, не трогая
# php/nginx/keydb/mariadb/ws-gateway/db-dump-cron. worker-api отделён от worker-*.
# Compose service worker-ai намеренно маппится на публичный image worker-ai-cpu.
.PHONY: workers-pull
workers-pull: validate-ai-image ## Pull только worker-образы: WORKER_RECREATE_PROFILE=remote|local|uBook|saVpn; опционально WORKER_RECREATE_SERVICES=<allowlist>
	@set -eu; \
	case "$(WORKER_RECREATE_PROFILE)" in \
	    remote|uBook|ubook) allowed="$(WORKER_RECREATE_REMOTE_SERVICES)" ;; \
	    local) allowed="$(WORKER_RECREATE_LOCAL_SERVICES)" ;; \
	    saVpn) allowed="$(WORKER_RECREATE_SAVPN_SERVICES)" ;; \
	    *) echo "workers-pull: unknown profile '$(WORKER_RECREATE_PROFILE)' (use remote, local, ubook or saVpn)"; exit 2 ;; \
	esac; \
	requested="$(WORKER_RECREATE_SERVICES)"; \
	if [ -z "$$requested" ]; then requested="$$allowed"; fi; \
	for service in $$requested; do case " $$allowed " in *" $$service "*) ;; *) echo "workers-pull: service '$$service' is not allowed by profile $(WORKER_RECREATE_PROFILE)"; exit 2 ;; esac; done; \
	requested=$$(printf '%s\n' $$requested | sort -u | tr '\n' ' '); \
	case " $$requested " in *" worker-ai "*) compose_profiles=ai ;; *) compose_profiles= ;; esac; \
	echo "workers-pull: profile=$(WORKER_RECREATE_PROFILE) effective services=$$requested compose_profiles=$${compose_profiles:-none}"; \
	available="$$(COMPOSE_PROFILES="$$compose_profiles" $(DC) config --services)"; \
	images="$$(COMPOSE_PROFILES="$$compose_profiles" $(DC) config --images)"; \
	for service in $$requested; do if ! printf '%s\n' "$$available" | grep -Fqx "$$service"; then echo "workers-pull: service '$$service' is not in the effective Compose service set"; exit 2; fi; done; \
	case " $$requested " in *" worker-ai "*) printf '%s\n' "$$images" | grep -Fqx "$(IMG)/worker-ai-cpu:$(IMAGE_TAG)" || { echo "workers-pull: worker-ai must render $(IMG)/worker-ai-cpu:$(IMAGE_TAG)"; exit 2; } ;; esac; \
	COMPOSE_PROFILES="$$compose_profiles" $(DC) pull $$requested

.PHONY: workers-recreate
workers-recreate: validate-ai-image ## Recreate только worker-образы: WORKER_RECREATE_PROFILE=remote|local|uBook|saVpn; опционально WORKER_RECREATE_SERVICES=<allowlist>
	@set -eu; \
	case "$(WORKER_RECREATE_PROFILE)" in \
	    remote|uBook|ubook) allowed="$(WORKER_RECREATE_REMOTE_SERVICES)" ;; \
	    local) allowed="$(WORKER_RECREATE_LOCAL_SERVICES)" ;; \
	    saVpn) allowed="$(WORKER_RECREATE_SAVPN_SERVICES)" ;; \
	    *) echo "workers-recreate: unknown profile '$(WORKER_RECREATE_PROFILE)' (use remote, local, ubook or saVpn)"; exit 2 ;; \
	esac; \
	requested="$(WORKER_RECREATE_SERVICES)"; \
	if [ -z "$$requested" ]; then requested="$$allowed"; fi; \
	for service in $$requested; do case " $$allowed " in *" $$service "*) ;; *) echo "workers-recreate: service '$$service' is not allowed by profile $(WORKER_RECREATE_PROFILE)"; exit 2 ;; esac; done; \
	requested=$$(printf '%s\n' $$requested | sort -u | tr '\n' ' '); \
	case " $$requested " in *" worker-ai "*) compose_profiles=ai ;; *) compose_profiles= ;; esac; \
	effective="$$requested"; \
	echo "workers-recreate: profile=$(WORKER_RECREATE_PROFILE) effective services=$$effective compose_profiles=$${compose_profiles:-none}"; \
	available="$$(COMPOSE_PROFILES="$$compose_profiles" $(DC) config --services)"; \
	for service in $$effective; do if ! printf '%s\n' "$$available" | grep -Fqx "$$service"; then echo "workers-recreate: service '$$service' is not in the effective Compose service set"; exit 2; fi; done; \
	COMPOSE_PROFILES="$$compose_profiles" $(DC) up -d --force-recreate --no-deps $$effective

.PHONY: worker-api-pull
worker-api-pull: validate-ai-image ## Pull только production worker-api image (server,api profiles)
	@set -eu; \
	available="$$(COMPOSE_PROFILES=server,api $(DC) config --services)"; \
	images="$$(COMPOSE_PROFILES=server,api $(DC) config --images)"; \
	printf '%s\n' "$$available" | grep -Fqx worker-api || { echo "worker-api-pull: worker-api is not in the effective Compose service set"; exit 2; }; \
	printf '%s\n' "$$images" | grep -Fqx "$(IMG)/worker-api:$(IMAGE_TAG)" || { echo "worker-api-pull: worker-api image does not match $(IMG)/worker-api:$(IMAGE_TAG)"; exit 2; }; \
	COMPOSE_PROFILES=server,api $(DC) pull worker-api

.PHONY: worker-api-recreate
worker-api-recreate: validate-ai-image ## SERVER: force-recreate production-only worker-api, --no-deps
	COMPOSE_PROFILES=server,api $(DC) up -d --force-recreate --no-deps worker-api

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
build-workers-test: build-data-test build-ffmpeg-test build-image-test build-libreoffice-test build-api-test build-metrics-test ## Build all 6 worker :test images (test deps pre-baked)

##@ Build worker-ai (remote / Harbor + local GPU)

# Двухслойная схема:
#   ai-base  (light: код + pure-python deps) → build-ai-cpu/build-ai-cuda ВСЕГДА
#            пересобирают ai-base ЛОКАЛЬНО (AI_BASE_LOCAL) перед сборкой рабочего
#            образа: working-образ никогда не подтянет протухший Harbor-тег.
#            push-ai-base — отдельный шаг, только для раздачи базы на другие хосты.
#   ai.cuda  (FROM ai-base + CUDA ML stack)   → LOCAL build на GPU-хосте, не публикуется.
#   ai.cpu   (FROM ai-base + CPU ML stack)    → LOCAL build для CI / CPU-only.
HARBOR_NS       ?= harbor.xakki.ru/convertor
# AI_BASE_IMAGE/AI_BASE_LOCAL — промежуточные локальные теги сборки, версию не пиннят
# намеренно (см. комментарии у build-ai-base). AI_CUDA_IMAGE/AI_CPU_IMAGE — то, что
# реально запускает docker-compose (CPU: worker-ai-cpu:${IMAGE_TAG:-latest}; CUDA:
# локальный worker-ai-cuda:<APP_VER>),
# поэтому CPU-образ обязан следовать IMAGE_TAG, а GPU-хост явно задаёт локальный тег.
AI_BASE_IMAGE   ?= $(HARBOR_NS)/worker-ai-base:latest
AI_BASE_LOCAL   ?= worker-ai-base:local
AI_CUDA_IMAGE   ?= worker-ai-cuda:$(APP_VER)
# CPU AI is a separately published repository; CUDA remains local-only.
AI_CPU_IMAGE    ?= $(HARBOR_NS)/worker-ai-cpu:$(IMAGE_TAG)

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

# ai.cpu/ai.cuda стадии FROM'ятся от AI_BASE_LOCAL (только локальный тег) — build-ai-base
# уже прогнал его с $(DOCKER_BUILD_FLAGS) (в т.ч. --pull у rebuild-workers, освежив реальный
# registry-базовый слой Dockerfile'а ai-base). Передавать --pull ВТОРОЙ раз сюда нельзя:
# docker трактует worker-ai-base:local как docker.io/library/worker-ai-base:local и падает
# "pull access denied" — тег локальный и минуту назад собран этим же прогоном.
AI_STAGE_BUILD_FLAGS := $(filter-out --pull,$(DOCKER_BUILD_FLAGS))

.PHONY: build-ai-base
build-ai-base: ## Build LIGHT ai-base (код + pure-python deps, без ML); теги APP_VER/latest + локальный
	docker build $(DOCKER_BUILD_FLAGS) \
	    -t $(HARBOR_NS)/worker-ai-base:$(APP_VER) \
	    -t $(HARBOR_NS)/worker-ai-base:latest \
	    -t $(AI_BASE_LOCAL) \
	    --build-arg APP_VER=$(APP_VER) \
	    --build-arg GIT_REVISION=$(GIT_REVISION) \
	    --build-arg SOURCE_STATE=$(BUILD_SOURCE_STATE) \
	    --build-arg IMAGE_REPOSITORY=$(HARBOR_NS)/worker-ai-base \
	    -f docker/workers/ai-base.Dockerfile .

.PHONY: push-ai-base
push-ai-base: build-ai-base ## Раздать ai-base на другие хосты: push в Harbor (build-ai-cpu/cuda от push не зависят)
	docker push $(HARBOR_NS)/worker-ai-base:$(APP_VER)
	docker push $(HARBOR_NS)/worker-ai-base:latest

.PHONY: build-ai-cuda
build-ai-cuda: bump-i build-ai-base ## LOCAL build: свежий ai-base + ai.cuda (CUDA ML). CUDA_ARCH=86 WITH_LLAMACPP=0|1
	docker build $(AI_STAGE_BUILD_FLAGS) -t $(AI_CUDA_IMAGE) -t worker-ai-cuda:latest \
	    --build-arg AI_BASE_IMAGE=$(AI_BASE_LOCAL) \
	    --build-arg CUDA_ARCH=$(CUDA_ARCH) \
	    --build-arg TORCH_CUDA_ARCH=$(TORCH_CUDA_ARCH) \
	    --build-arg WITH_LLAMACPP=$(WITH_LLAMACPP) \
	    --build-arg APP_UID=$(APP_UID) \
	    --build-arg APP_GID=$(APP_GID) \
	    --build-arg APP_VER=$(APP_VER) \
	    --build-arg WORKER_BUILD="$$(cat $(CURDIR)/.i 2>/dev/null || echo 0)" \
	    --build-arg GIT_REVISION=$(GIT_REVISION) \
	    --build-arg SOURCE_STATE=$(BUILD_SOURCE_STATE) \
	    --build-arg IMAGE_REPOSITORY=worker-ai-cuda \
	    -f docker/workers/ai.cuda.Dockerfile .

.PHONY: build-ai-cpu
build-ai-cpu: bump-i build-ai-base ## LOCAL build: свежий ai-base + ai.cpu (CPU ML stack, GPU не нужен)
	docker build $(AI_STAGE_BUILD_FLAGS) -t $(AI_CPU_IMAGE) \
	    --build-arg AI_BASE_IMAGE=$(AI_BASE_LOCAL) \
	    --build-arg APP_UID=$(APP_UID) \
	    --build-arg APP_GID=$(APP_GID) \
	    --build-arg APP_VER=$(APP_VER) \
	    --build-arg WORKER_BUILD="$$(cat $(CURDIR)/.i 2>/dev/null || echo 0)" \
	    --build-arg GIT_REVISION=$(GIT_REVISION) \
	    --build-arg SOURCE_STATE=$(BUILD_SOURCE_STATE) \
	    --build-arg IMAGE_REPOSITORY=$(HARBOR_NS)/worker-ai-cpu \
	    -f docker/workers/ai.cpu.Dockerfile .

##@ Release (Harbor)

# Явный поимённый список runnable-образов на push — НИКАКИХ tag-glob'ов (решение §7:
# после смены IMAGE_NS на Harbor-namespace build_test_img тегает :test прямо в Harbor,
# такой образ никогда не должен уехать push'ем). worker-ai-cuda НЕ публикуется —
# остаётся локальной сборкой GPU-хоста (§1, карта CNV-7-cuda-worker-ai-rebuild-gpu-host).
RELEASE_IMAGES := worker-libreoffice worker-ffmpeg worker-image worker-data worker-api metrics-exporter ws-gateway

.PHONY: release-tag
release-tag:  # служебный prereq release-workers — печатает RELEASE_TAG до старта сборки
	@echo "release-workers: RELEASE_TAG=$(RELEASE_TAG) → $(HARBOR_NS)"

.PHONY: release-workers
release-workers: IMAGE_TAG := $(RELEASE_TAG)
release-workers: release-tag release-guard build-server-workers build-ai-cpu ## SERVER release: build incl. worker-api + push only APP_VER/latest (без :test и worker-ai-cuda)
	@set -e; for name in $(RELEASE_IMAGES); do \
	    echo "push $(HARBOR_NS)/$$name:$(RELEASE_TAG) and :latest"; \
	    docker tag $(IMG)/$$name:latest $(HARBOR_NS)/$$name:$(RELEASE_TAG); \
	    docker tag $(IMG)/$$name:latest $(HARBOR_NS)/$$name:latest; \
	    docker push $(HARBOR_NS)/$$name:$(RELEASE_TAG); \
	    docker push $(HARBOR_NS)/$$name:latest; \
	done
	docker tag $(AI_CPU_IMAGE) $(HARBOR_NS)/worker-ai-cpu:$(RELEASE_TAG)
	docker tag $(AI_CPU_IMAGE) $(HARBOR_NS)/worker-ai-cpu:latest
	docker push $(HARBOR_NS)/worker-ai-cpu:$(RELEASE_TAG)
	docker push $(HARBOR_NS)/worker-ai-cpu:latest
	docker push $(HARBOR_NS)/worker-ai-base:$(RELEASE_TAG)
	docker push $(HARBOR_NS)/worker-ai-base:latest
	@$(MAKE) publish-deploy-gist

# Публикация deploy/ в публичный gist (проекция для curl|bash). Warn+skip если
# DEPLOY_GIST_ID пуст или нет gh/jq — релиз образов при этом уже успешен.
.PHONY: publish-deploy-gist
publish-deploy-gist: ## Обновить публичный gist содержимым deploy/ (нужен DEPLOY_GIST_ID; иначе skip)
	@bash deploy/publish-to-gist.sh

.PHONY: rebuild-workers
rebuild-workers: DOCKER_BUILD_FLAGS := --no-cache --pull
rebuild-workers: build-workers build-ai-cpu ## Full --no-cache --pull пересборка (бамп deps / CVE-рефреш базового образа); НЕ пушит — следующий release-workers перезальёт ~3 ГБ

##@ Queue / Workers

.PHONY: worker-logs
worker-logs: ## REMOTE-safe: tail logs 6 worker-контейнеров, вкл. worker-ai; без server-only worker-api
	$(DC) logs -f worker-libreoffice worker-ffmpeg-audio worker-ffmpeg-video worker-image worker-data worker-ai

.PHONY: server-worker-logs
server-worker-logs: ## SERVER: tail logs 7 worker-контейнеров, вкл. production-only worker-api
	COMPOSE_PROFILES=server,api $(DC) logs -f worker-libreoffice worker-ffmpeg-audio worker-ffmpeg-video worker-image worker-data worker-ai worker-api

# Свой fluent-bit нужен ТОЛЬКО remote-хосту (на главном сервере логи шлём в общий
# host-wide сборщик на ${EXT_FLUENT_PORT}, и порт уже занят им).
.PHONY: fluent-up
fluent-up: ## REMOTE-хост: поднять fluent-bit+logrotate (отдельный Compose, без CUDA guard)
	$(DC_FLUENT) up -d fluent-bit logrotate

.PHONY: fluent-recreate
fluent-recreate: ## REMOTE-хост: force-recreate fluent-bit+logrotate (отдельный Compose, без CUDA guard)
	$(DC_FLUENT) up -d --force-recreate fluent-bit logrotate

.PHONY: fluent-restart
fluent-restart: ## Restart fluent-bit-сайдкара (отдельный Compose, без CUDA guard)
	$(DC_FLUENT) restart fluent-bit

.PHONY: fluent-logs
fluent-logs: ## Tail логи fluent-bit-сайдкара
	$(DC_FLUENT) logs -f fluent-bit

# Очереди — это Streams `conv.*`, а не списки. XLEN накопителен и не является
# backlog; первичный сигнал для группы `convertor` — pending и idle самой старой
# pending-записи. `-a` подставляем только при непустом пароле — keydb.conf идёт без
# requirepass, и `-a ""` роняет команду с AUTH failed.
.PHONY: queue-status
queue-status: ## Кратко показать pending/idle очередей и накопительный XLEN (не backlog)
	@docker exec $(KEYDB_CONT) keydb-cli $(if $(REDIS_PASSWORD),--no-auth-warning -a "$(REDIS_PASSWORD)") -n $(REDIS_QUEUE_DB) \
	    eval "local ks=redis.call('keys','conv.*') local out={} table.sort(ks) for _,k in ipairs(ks) do if k ~= 'conv.dead' and redis.call('type',k).ok == 'stream' then local pending=0 local idle=0 local group_found=false local groups=redis.call('xinfo','groups',k) for _,g in ipairs(groups) do local name local group_pending=0 for i=1,#g,2 do if g[i]=='name' then name=g[i+1] elseif g[i]=='pending' then group_pending=tonumber(g[i+1]) or 0 end end if name == 'convertor' then group_found=true pending=group_pending if pending > 0 then local oldest=redis.call('xpending',k,name,'-','+',1) if oldest[1] then idle=tonumber(oldest[1][3]) or 0 end end break end end out[#out+1]=k..': pending[convertor]='..pending..(group_found and '' or ' (group absent)')..', oldest-pending-idle-ms='..idle..', cumulative-xlen-not-backlog='..redis.call('xlen',k) end end local dead_len=0 if redis.call('type','conv.dead').ok == 'stream' then dead_len=redis.call('xlen','conv.dead') end out[#out+1]='DLQ conv.dead: xlen='..dead_len return out" 0

# RO-диагностика стрима: XINFO/XRANGE/XPENDING — никогда XDEL/XTRIM/XACK.
.PHONY: queue-inspect
queue-inspect: ## RO-диагностика очередей; опционально STREAM=conv.<тип>
	@requested="$${STREAM:-}"; \
	out="$$(docker exec $(KEYDB_CONT) keydb-cli $(if $(REDIS_PASSWORD),--no-auth-warning -a "$(REDIS_PASSWORD)") -n $(REDIS_QUEUE_DB) \
	    eval "local requested=ARGV[1] or '' local keys=redis.call('keys','conv.*') local streams={} for _,k in ipairs(keys) do if k ~= 'conv.dead' and redis.call('type',k).ok == 'stream' then streams[#streams+1]=k end end table.sort(streams) if requested ~= '' then local allowed=false for _,k in ipairs(streams) do if k == requested then allowed=true break end end if not allowed then return {'__QUEUE_INSPECT_INVALID__: STREAM='..requested..' is not a discovered permitted conversion stream'} end streams={requested} end local out={} if #streams == 0 then out[#out+1]='No permitted conversion streams found.' end for _,k in ipairs(streams) do out[#out+1]='== XINFO GROUPS '..k..' ==' local groups=redis.call('xinfo','groups',k) if #groups == 0 then out[#out+1]='(no groups)' else for _,g in ipairs(groups) do local name='' local fields={} for i=1,#g,2 do fields[g[i]]=g[i+1] end name=fields['name'] out[#out+1]='group='..name..', consumers='..tostring(fields['consumers'] or 0)..', pending='..tostring(fields['pending'] or 0)..', last-delivered-id='..tostring(fields['last-delivered-id'] or '0-0') local start='-' local found=false while true do local entries=redis.call('xpending',k,name,start,'+',1000) if #entries == 0 then break end found=true for _,entry in ipairs(entries) do out[#out+1]='XPENDING RANGE '..name..': id='..entry[1]..', consumer='..entry[2]..', idle-ms='..tostring(entry[3])..', deliveries='..tostring(entry[4]) end if #entries < 1000 then break end start='('..entries[#entries][1] end if not found then out[#out+1]='XPENDING RANGE '..name..': empty PEL' end end end end out[#out+1]='== DLQ conv.dead (XRANGE) ==' local dead_type=redis.call('type','conv.dead').ok if dead_type == 'none' then out[#out+1]='DLQ conv.dead: absent (empty)' elseif dead_type ~= 'stream' then out[#out+1]='DLQ conv.dead: not a stream (type='..dead_type..')' else local dead=redis.call('xrange','conv.dead','-','+') if #dead == 0 then out[#out+1]='DLQ conv.dead: empty' else for _,entry in ipairs(dead) do local values={} for i=1,#entry[2],2 do values[#values+1]=entry[2][i]..'='..entry[2][i+1] end out[#out+1]='DLQ conv.dead: id='..entry[1]..', '..table.concat(values,', ') end end end return out" 0 "$$requested")"; \
	status=$$?; \
	if [ "$$status" -ne 0 ]; then exit "$$status"; fi; \
	printf '%s\n' "$$out"; \
	case "$$out" in __QUEUE_INSPECT_INVALID__:*) exit 2;; esac

.PHONY: dlq-inspect
dlq-inspect: ## RO-инспекция DLQ-стрима STREAM=conv.dead (default): XINFO STREAM/GROUPS + XRANGE + XPENDING/группа
	@S="$(if $(STREAM),$(STREAM),conv.dead)"; \
	echo "== XINFO STREAM $$S =="; \
	docker exec $(KEYDB_CONT) keydb-cli $(if $(REDIS_PASSWORD),--no-auth-warning -a "$(REDIS_PASSWORD)") -n $(REDIS_QUEUE_DB) XINFO STREAM "$$S"; \
	echo "== XRANGE $$S - + =="; \
	docker exec $(KEYDB_CONT) keydb-cli $(if $(REDIS_PASSWORD),--no-auth-warning -a "$(REDIS_PASSWORD)") -n $(REDIS_QUEUE_DB) XRANGE "$$S" - +; \
	echo "== XINFO GROUPS $$S =="; \
	docker exec $(KEYDB_CONT) keydb-cli $(if $(REDIS_PASSWORD),--no-auth-warning -a "$(REDIS_PASSWORD)") -n $(REDIS_QUEUE_DB) XINFO GROUPS "$$S"; \
	echo "== XINFO CONSUMERS per group (idle ms = liveness) =="; \
	docker exec $(KEYDB_CONT) keydb-cli $(if $(REDIS_PASSWORD),--no-auth-warning -a "$(REDIS_PASSWORD)") -n $(REDIS_QUEUE_DB) \
	    eval "local out={} local groups=redis.call('xinfo','groups',KEYS[1]) for _,g in ipairs(groups) do local name for i=1,#g,2 do if g[i]=='name' then name=g[i+1] end end local cs=redis.call('xinfo','consumers',KEYS[1],name) out[#out+1]=name..': '..cjson.encode(cs) end return out" 1 "$$S"; \
	echo "== XPENDING per group =="; \
	docker exec $(KEYDB_CONT) keydb-cli $(if $(REDIS_PASSWORD),--no-auth-warning -a "$(REDIS_PASSWORD)") -n $(REDIS_QUEUE_DB) \
	    eval "local out={} local groups=redis.call('xinfo','groups',KEYS[1]) for _,g in ipairs(groups) do local name for i=1,#g,2 do if g[i]=='name' then name=g[i+1] end end local p=redis.call('xpending',KEYS[1],name) out[#out+1]=name..': '..cjson.encode(p) end return out" 1 "$$S"

##@ Worker tests

.PHONY: test-makefile-worker-pull
test-makefile-worker-pull: ## Structural fake-Compose tests for profile-scoped worker pulls
	python3 -m pytest workers/tests/test_makefile_worker_pull.py -p no:cacheprovider -q

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
test-python: test-python-data test-python-ffmpeg test-python-image test-python-libreoffice test-python-metrics test-python-ai test-python-api ## 7 unit-сюит по воркерам ВНУТРИ образов (без e2e и drift; стенд не нужен)

.PHONY: test-python-api
test-python-api: build-api-test ## Unit + schema-drift тесты worker-api в его контейнере
	$(call RUN_PYTEST_TEST,$(IMG)/worker-api:test,workers/tests/test_api_worker.py workers/tests/test_api_schema_drift.py workers/tests/test_api_generic_contract.py workers/tests/test_gonka_provider.py)

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

# test-drift гоняется на хосте: PHP-сторона — docker exec dump-matrix.php
# (нужен поднятый php + непустая БД); Python CAPABILITIES — AST/static extract
# из workers/*/worker.py (stdlib only, без httpx/Pillow — CI .venv-ci = pytest).
# test_worker_type_drift.py — второй guard: text-parse PHP-enum WorkerType vs
# 3 статических зеркала (ws_client.py, keydb.py, messenger.yaml).
# test_catalog_drift.py — третий guard (CNV-71-01): committed
# app-symfony/config/catalog/worker_capabilities.json vs свежий AST-extract;
# bare-host, тех же зависимостей что и test_routing_drift.py не требует.
# test_capabilities_mutation_guard.py — hardening-guard самого экстрактора
# (CNV-71 post-71-01): пост-присваивание мутации (subscript/augassign/
# .update()/…), которые он должен ловить как CapabilitiesExtractionError,
# а не молча терять из графа CAPABILITIES; bare-host, тех же зависимостей
# что и test_catalog_drift.py не требует.
.PHONY: test-drift
test-drift: ## Drift guards: routing/types/catalog + worker-api ops config + mutation-guard
	$(REQUIRE_TEST)
	PYTHONPATH=. pytest workers/tests/test_routing_drift.py workers/tests/test_worker_type_drift.py workers/tests/test_catalog_drift.py workers/tests/test_capabilities_mutation_guard.py workers/tests/test_makefile_gateway_sha.py workers/tests/test_worker_api_ops_config.py -v

# Регенерация каталога — 2 стадии. Stage 1: bare host, stdlib only (тот же
# принцип, что и test-drift Python-сторона) — gen_worker_capabilities.py не
# тянет worker-рантайм зависимости, docker не нужен. Stage 2: PHP-редукция
# (CNV-71-01) через ConversionRegistry — требует поднятый php-контейнер
# ($(CONSOLE) = docker exec, определён в app-symfony/Makefile).
.PHONY: formats-catalog
formats-catalog: ## Regen каталог форматов: CAPABILITIES воркеров → worker_capabilities.json → conversion_pairs.json (нужен `make up`)
	PYTHONPATH=. python3 workers/tools/gen_worker_capabilities.py
	$(CONSOLE) app:catalog:generate-conversion-pairs

.PHONY: test-gateway
test-gateway: validate-ai-image build-data-test ## KeyDB-слой WS-Gateway на РЕАЛЬНОМ keydb тест-стенда (async, pytest-asyncio)
	$(REQUIRE_TEST)
	$(DC) up -d --wait keydb
	docker run --rm -u root \
	    --network $(COMPOSE_PROJECT_NAME)-network \
	    -e REDIS_HOST=$(KEYDB_CONT) -e REDIS_DB=$(REDIS_QUEUE_DB) \
	    -v "$(CURDIR):/src:ro" -w /src -e PYTHONPATH=/src -e HOME=/tmp \
	    $(IMG)/worker-data:test \
	    python3 -m pytest workers/tests/test_gateway_keydb.py workers/tests/test_gateway_ws.py workers/tests/test_gateway_relay.py workers/tests/test_gateway_liveness.py workers/tests/test_gateway_liveness_push.py workers/tests/test_gateway_reclaim_dlq.py workers/tests/test_gateway_dlq_consumer.py workers/tests/test_gateway_expiry.py workers/tests/test_gateway_progress_status.py workers/tests/test_ws_client.py workers/tests/test_ws_transport_integration.py -p no:cacheprovider -ra -v

# Общий docker-run для e2e/smoke (KeyDB+S3+DB env → pytest -m e2e).
define RUN_E2E_PYTEST
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
endef

.PHONY: test-e2e
test-e2e: build-workers build-ffmpeg-test ## Реальный S3 in/out e2e (все категории) на тест-стенде
	$(REQUIRE_TEST)
	$(MAKE) COMPOSE_PROFILES=server,test,ai up migrate
	$(RUN_E2E_PYTEST)

# Smoke = тот же e2e-прогон локальных worker-категорий
# document/image/audio/video/data/ai; provider-backed api здесь намеренно нет.
# AI-профиль включается явно (в .env.test по умолчанию ai выключен — тяжёлый образ).
# Вызов: `make smoke` (пере-вход TEST=1) или `make TEST=1 smoke-run`.
.PHONY: smoke-run
smoke-run: build-workers build-ffmpeg-test ## Internal: smoke e2e на тест-стенде (+ AI profile)
	$(REQUIRE_TEST)
	$(MAKE) COMPOSE_PROFILES=server,test,ai up migrate
	$(RUN_E2E_PYTEST)

# Public-API integration e2e (PHPUnit, group=integration): POST /convert → poll
# status → download по РЕАЛЬНОМУ HTTP против стенда, по одному кейсу на категорию
# (image/data/ffmpeg/document). build-workers — обязательный prereq: стенд поднимает
# воркеры из :latest, и без пересборки тест гонял бы СТАРЫЙ код воркеров.
.PHONY: test-api-integration
test-api-integration: build-workers ## Public-API e2e (PHPUnit group=integration): image/data/ffmpeg/document
	$(REQUIRE_TEST)
	$(MAKE) up migrate
	$(DC) exec -T php php vendor/bin/phpunit --group integration
