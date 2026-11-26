# xne: make test runs the unit tests (pure PHP runner, scratch trees under var/test);
# make it runs the integration tests too (real releases, throwaway installs and databases under $(XNE_IT_DIR)).
PHP ?= php
XNE_IT_DIR ?= $(CURDIR)/var/it
.PHONY: test it check
test:
	$(PHP) tests/run.php
it:
	XNE_IT_DIR=$(XNE_IT_DIR) XNE_IT_PHP=$(PHP) $(PHP) tests/run.php
check:
	for f in bin/xne cronjobs/xne-check-all $$(find lib classes tests extension -name '*.php'); do $(PHP) -l $$f >/dev/null || exit 1; done; bash -n install.sh
