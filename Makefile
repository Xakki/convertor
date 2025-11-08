SHELL = /bin/bash
### https://makefiletutorial.com/


include .env
export

libreoffice :=


login:
	docker login ${DOCKER_HOST} -u ${DOCKER_USER} -p ${DOCKER_PASS}

build-libreoffice:
	#docker pull linuxserver/libreoffice:latest
	docker build -t xakki/libreoffice:latest libreoffice/

push-libreoffice:
	docker push xakki/libreoffice:latest

test:
	pytest

my-test:
	./libreoffice/test.sh


