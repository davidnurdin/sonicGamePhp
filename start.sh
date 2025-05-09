#!/bin/bash

php -d 'extension=sdl.so' -d 'extension=sdl_image.so' -d 'extension=sdl_ttf.so' -d 'extension=sdl_mixer.so' app.php

