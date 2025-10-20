.PHONY: all

all: test

test:
	@skernel build --dev --debug
	@chmod +x target/release/bin
	@target/release/bin start