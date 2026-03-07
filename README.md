# NextSound
###### (Title is WIP)

##### Docker Container is here:
[NextSound on Docker Hub](https://hub.docker.com/repository/docker/novakeith/nextsound/general)

## What is it?
This was designed to be an open source, self-hostable alternative to Soundcloud for musicians or podcasters or anyone working with audio.

As I looked around the web, I did not see a convincing alternative to Soundcloud that sat in the middle of the venn diagram I had in my mind.

This project aims to be simple at one thing (sharing audio and soliciting feedback on that audio) without any of the extra features I saw in alternative tools. 

## What can it do today?
As of 3/5/26, you can:
- Spin up an instance using docker;
- have a single user install where the admin can upload tracks and set/edit/delete metadata on those tracks (project notes, changelog between versions);
- upload multiple versions of the same track (so a listener can see progressions)
- delete an entire project OR delete a single version of a track;
- Admins can link to a track directly OR mark a track as 'public', which will display it in a list on the home page;
- Listeners can click on a waveform and leave a comment.
- use admin account to delete user comments
- comments can be disabled site-wide. They will still appear for the admin though, so you can use this as a 'note to self' thing on tracks while still sharing the track with other people.
- allow tracks to be marked for download

## What will it do eventually?
I want to at some point add:

- let users edit/delete their own comments
- group songs into a playlist (the idea is the admin/musician can create an album that can be linked to so it plays in order for listeners)
- re-work the UI to be nicer looking
- - For example, with a lot of tracks, the admin UI is going to be unwieldy. 
- light mode / dark mode AND/OR allow admins to create custom themes from a dashboard (I have rudimentary color picking for some buttons right now)
- allow admins to tag their projects / versions (ie. "sketch" / "mixing" / "mastering" / "completed")
- probably better security checks; right now its pretty rudimentary + assumes a single admin who sets a secure password ahead of time
- - need MIME type checking for file uploads.

## What is the expected use case?
I see myself using it this way:
- Self host this however you want to do that since its just a docker container
- Link to it from my main site (knova.net) as a sub-section (ie. "Works in Progress") where I can solicit feedback
- This is where theming would come in so it feels like a more natural fit
- Send links to friends or share on internet forums, to solicit feedback

However, as I mentioned above, I can see some value in maybe having a rudimentary way to collaborate on editing other audio, like podcasts.


## Installation (Easy)
1. create a docker-compose.yml file,  example given below. Change the port and the .env file if you want.
```
services:
  nextsound:
    image: novakeith/nextsound:latest
    container_name: nextsound_app
    # change this port if you want. Default is 8027.
    ports:
      - "8027:80"
    volumes:
      - .:/var/www/html
      - ./data:/var/www/html/data
      - ./uploads:/var/www/html/uploads
    # Make sure you open the .env file and update the defaults. Example .env file is at: .env.example
    env_file:
      - .env
    restart: always
```
2. Grab the .env.example file from this repo and save it as .env in the same directory as your docker-compose.yml. Make necessary changes to the .env file before exposing this container publicly!
3. run ```docker compose up -d``` in a terminal in the same folder where your docker-compose.yml is
4. navigate to your service in your browser (ie. 127.0.0.1:8027) and login using the admin password you set in the .env file. 
5. Everything in the database should be created on first run

## Installation (Not as Easy)
1. Download this repo
2. run ```docker compose build``` to create the docker image on your system.
3. run ```cp .env.example .env``` and then edit .env so your environment variables are set properly. Do not run the docker without editing this first!
4. run the service with ```docker compose up -d```
5. The docker container will create the necessary folder structure, as well as the database on first run.

## Shutting down the service
If you want to cleanly reset all the data this service has created but retain the install files to run again in the future, you can run CLEANUP.sh - this will erase everything in the /data/ and /uploads/ folders.