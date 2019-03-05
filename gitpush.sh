#!/bin/bash

#rm -rf .git
#git init
git add .
git commit -m "$1"
git remote add origin https://github.com/ppexxi/eurosms_php.git
git remote -v
git push -u origin master --force
