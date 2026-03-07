#!/bin/bash
# wipe everything in the ./data and ./uploads folders
# good way to wipe an install
# and yes I'm aware this is just two 'rm' commands, I'm just lazy.
echo "---------------"
echo "This will erase all of your NextSound data and uploads."
echo "There is no undo! Please proceed with caution."
echo "---------------"
sudo rm -ri ./data/*.db 
find uploads/ -type f ! -name '.htaccess' -print0 | sudo xargs -0 rm -vf
find uploads/ -type d -empty -print0 | sudo xargs -0 rmdir -v
