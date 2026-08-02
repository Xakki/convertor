# syntax=docker/dockerfile:1.7
# worker-libreoffice: document/markup conversions via LibreOffice + pandoc + poppler.
# Streams consumer (conv.document) + S3 I/O — same pattern as worker-ffmpeg/-image.
FROM python:3.12-slim

ARG DEBIAN_FRONTEND=noninteractive
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    HOME=/home/app \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

# Components:
#   libreoffice-writer/-calc/-impress  document/spreadsheet/presentation conversions
#   pandoc                    markdown reader/writer + .docx/.odt → md (GFM)
#   poppler-utils             pdftotext (pdf→txt/md/docx), pdftoppm (pdf→jpg)
#   libetonyek-0.1-1          Apple Pages import (soffice filter; seed↔worker sync)
#
# Fonts cover MS Office substitutes (liberation), broad Latin/Cyrillic/Greek
# (dejavu/freefont), universal Unicode (noto-core + cjk + color-emoji), LaTeX
# (lmodern/texgyre), linguistic (sil-gentium), unknown-script fallback
# (droid-fallback). Hyphenation patterns for major European languages.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libreoffice-writer libreoffice-calc libreoffice-impress \
        libreoffice-core libreoffice-l10n-ru libreoffice-l10n-de libreoffice-l10n-fr \
        pandoc poppler-utils libetonyek-0.1-1 \
        fonts-liberation fonts-liberation2 \
        fonts-dejavu fonts-dejavu-extra \
        fonts-freefont-ttf fonts-opensymbol \
        fonts-noto-core fonts-noto-mono fonts-noto-cjk fonts-noto-color-emoji \
        fonts-sil-gentium fonts-lmodern fonts-texgyre fonts-droid-fallback \
        hyphen-en-us hyphen-ru hyphen-de hyphen-fr hyphen-it hyphen-es \
        hyphen-pl hyphen-uk hyphen-cs hyphen-nl \
        locales \
        curl ca-certificates \
        tini \
    && sed -i -E 's/^# (en_US\.UTF-8|ru_RU\.UTF-8|de_DE\.UTF-8|fr_FR\.UTF-8|es_ES\.UTF-8|it_IT\.UTF-8|zh_CN\.UTF-8|ja_JP\.UTF-8|ko_KR\.UTF-8) UTF-8/\1 UTF-8/' /etc/locale.gen \
    && locale-gen \
    && useradd -m -u 1000 -d /home/app app \
    && mkdir -p /work /app \
    && chown -R app:app /work /app /home/app \
    && find /usr/lib -name 'libetonyek-*.so*' 2>/dev/null | head -1 | grep -q . \
        || (echo "FATAL: libetonyek .so missing after apt install libetonyek-0.1-1" >&2 && exit 1) \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Deps before COPY source for layer caching (conversions shell out → redis+boto3 only)
COPY docker/workers/requirements-libreoffice.txt /tmp/requirements.txt
RUN --mount=type=cache,target=/root/.cache/pip \
    PIP_CACHE_DIR=/root/.cache/pip PIP_NO_CACHE_DIR=0 pip install -r /tmp/requirements.txt

WORKDIR /app

COPY --chown=app:app workers/common/ /app/workers/common/
COPY --chown=app:app workers/libreoffice/ /app/workers/libreoffice/

ENV WORKER_MODULE=workers.libreoffice.worker

# Запекаем version в образ (§4/§8): APP_VER → ENV (ws_client читает os.getenv);
# WORKER_BUILD → /app/.i (ws_client читает файл). Образ одностадийный — ARG видны здесь.
ARG APP_VER=0
ARG WORKER_BUILD=0
ENV APP_VER=${APP_VER}
RUN printf '%s' "${WORKER_BUILD}" > /app/.i

USER app

HEALTHCHECK --interval=30s --timeout=10s --start-period=20s --retries=3 \
    CMD soffice --version > /dev/null 2>&1 || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["sh", "-c", "python3 -m ${WORKER_MODULE}"]
