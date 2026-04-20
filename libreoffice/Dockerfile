# syntax=docker/dockerfile:1.7
FROM debian:bookworm-slim

ARG DEBIAN_FRONTEND=noninteractive
ENV PYTHONUNBUFFERED=1 \
    PORT=6000 \
    SHARE_DIR=/share \
    HOME=/home/app \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

# Components:
#   libreoffice-writer/-core  primary converter (doc, docx, odt, rtf, html, ...)
#   pandoc                    .docx/.odt -> Markdown (GFM tables, images via --extract-media)
#   poppler-utils             pdftotext fallback for PDF inputs (LO loses text on image-only PDFs)
#
# Fonts cover MS Office substitutes (liberation), broad Latin/Cyrillic/Greek (dejavu/freefont),
# universal Unicode (noto-core + cjk + color-emoji), LaTeX (lmodern/texgyre), linguistic (sil-gentium),
# fallback for unknown scripts (droid-fallback). Hyphenation patterns for major European languages.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libreoffice-writer libreoffice-core libreoffice-l10n-ru libreoffice-l10n-de libreoffice-l10n-fr \
        pandoc poppler-utils \
        python3 python3-aiohttp \
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
    && mkdir -p /share /proxy \
    && chown -R app:app /share /proxy /home/app \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

WORKDIR /share
COPY --chown=app:app main.py /proxy/main.py

USER app

EXPOSE 6000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT}/health" || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["python3", "/proxy/main.py"]
