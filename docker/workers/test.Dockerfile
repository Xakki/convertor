# Общий test-stage wrapper: слой поверх runtime-образа воркера.
# В production-образы (:<latest>) никогда не попадает.
# Собирается ТОЛЬКО build-*-test Makefile-таргетами.
#
# BUILD:
#   docker build --build-arg BASE_IMAGE=<stem>:latest \
#                -t <stem>:test -f docker/workers/test.Dockerfile .
ARG BASE_IMAGE
FROM ${BASE_IMAGE}

USER root
COPY workers/requirements-test.txt /tmp/requirements-test.txt
RUN pip install --no-cache-dir -r /tmp/requirements-test.txt && rm /tmp/requirements-test.txt
USER app
