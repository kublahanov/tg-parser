#!/bin/bash

# Забрать изменения из оригинального проекта
git fetch upstream
git checkout master
git merge upstream/master
git push origin master

# Переключиться на свою ветку и слить изменения
git checkout tg-parser
git merge master
