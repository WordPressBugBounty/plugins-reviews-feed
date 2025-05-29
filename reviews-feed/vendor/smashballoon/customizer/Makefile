.PHONY: build package

build:
	npm install --prefix ./sb-common/sb-customizer
	npm run build --prefix ./sb-common/sb-customizer

package: build
	rm -rf ./prod
	mkdir -p ./prod/sb-common/sb-customizer
	rsync -r --filter=". ./build/filter" ./sb-common/sb-customizer/build/* ./prod/sb-common/sb-customizer/build/
	cd ./prod && zip -r ./sb-customizer-$(version).zip ./sb-common
	rm -rf ./prod/sb-common

repalce-composer: build
	rm -rf ../customizer-tmp
	mkdir -p ../customizer-tmp/sb-common/sb-customizer && mv ./sb-common/sb-customizer/assets ../customizer-tmp/sb-common/sb-customizer && mv ./sb-common/sb-customizer/build ../customizer-tmp/sb-common/sb-customizer && mv ./app ../customizer-tmp/
	cd ../ && rm -rf customizer && mv customizer-tmp customizer
