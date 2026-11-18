#!/bin/bash

# change php version
update-alternatives --config php

# Update Service Worker
npm run update-sw

# start php
./yii serve -p 30201
