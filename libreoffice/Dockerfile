FROM openjdk:8-jre-slim
ARG DEBIAN_FRONTEND=noninteractive

## https://smyt.ru/blog/sozdaem-server-dlya-konvertacii-docx-v-pdf/

## https://github.com/hannesdejager/docker-libreoffice-api/blob/master/Dockerfile
## https://github.com/linuxserver/docker-libreoffice/blob/master/Dockerfile
# https://github.com/renothing/docker-libreoffice/blob/master/Dockerfile
# https://github.com/ipunkt/docker-libreoffice-headless/blob/master/Dockerfile

ENV REFRESHED_AT="2025–08–14"
ENV PYTHONUNBUFFERED=1

RUN apt-get update && apt-get -y --no-install-recommends install \
    libreoffice libreoffice-writer libreoffice-common libreoffice-java-common \
    python3 python3-pip htop \
    locales-all unoconv hyphen-af hyphen-en-us hyphen-fr hyphen-de hyphen-it hyphen-ru \
    fonts-dejavu fonts-dejavu-core fonts-dejavu-extra \
    fonts-droid-fallback fonts-dustin fonts-f500 fonts-fanwood fonts-freefont-ttf fonts-liberation \
    fonts-lmodern fonts-lyx fonts-sil-gentium fonts-texgyre fonts-tlwg-purisa fonts-opensymbol \
    && pip install aiohttp \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get autoremove --purge -y && apt-get autoclean -y && apt-get clean -y

WORKDIR /share

ADD main.py /proxy/main.py

ENV PORT=80

CMD ["python3", "/proxy/main.py"]
