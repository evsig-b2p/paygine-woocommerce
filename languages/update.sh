#!/bin/sh

xgettext ../paygine-payment_method.php --keyword=__
msgmerge -N paygine-payment_method-ru_RU.po messages.po >paygine-payment_method-ru_RU.po.new
rm messages.po
