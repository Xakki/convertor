SHELL = /bin/bash
### https://makefiletutorial.com/


include .env
export

build-library:
	docker build -t ${HOST}/library/libreoffice:latest -t xakki/libreoffice:latest libreoffice/

push-library:
	docker push ${HOST}/library/libreoffice:latest
	docker push xakki/libreoffice:latest

