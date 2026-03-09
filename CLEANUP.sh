#!/bin/bash
# wipe everything in the ./data and ./uploads folders
# good way to wipe an install

echo "---------------"
echo "This will erase all of your NextSound data and uploads."
echo "There is no undo! Please proceed with caution."
echo "---------------"

# pause for user input
read -p "Are you absolutely sure you want to proceed? (y/N): " confirm

if [[ "$confirm" == [yY] || "$confirm" == [yY][eE][sS] ]]; then
    echo "Starting cleanup..."

	sudo rm -rf ./data/*.db
	sudo rm -ri ./uploads/

	# recreate uploads and move HTaccess back from the template
	sudo mkdir ./uploads/
	sudo cp ./assets/.htaccess-uploads ./uploads/.htaccess
	
	echo "Cleanup complete. Your install has been reset."

else
    echo "Cleanup aborted. No files were harmed."
    exit 1
fi