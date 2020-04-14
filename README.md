[![Layers](https://images.microbadger.com/badges/image/edyan/satis-server.svg)](https://microbadger.com/images/edyan/satis-server "Get your own image badge on microbadger.com")
[![Docker Pulls](https://img.shields.io/docker/pulls/edyan/satis-server.svg)](https://hub.docker.com/r/edyan/satis-server/)
[![Build Status](https://travis-ci.com/edyan/docker-php.svg?branch=master)](https://travis-ci.com/edyan/docker-php)

## Composer Satis Server
Docker Hub: https://hub.docker.com/r/edyan/satis-server

Satis with a micro-api on top of it to interact and have a simple
packagist private repository.

## Start Docker Image
```bash
# Mount /build directory to save locally the output of satis, including the 
# packages if they are uploaded
docker run \
    -p 8080:8080 \
    -v $(pwd)/volumes/build/:/build \
    --name satis-server \ 
    edyan/satis-server:latest
```

## satis.json
Satis works with a `satis.json` file that can be generated with the
`/init` endpoint (see below).

You can also edit it manually. For example to have a local repository with
local artifacts :
```json
{
    "name": "edyan/satis",
    "homepage": "https://my.satis.repo",
    "repositories": [
        {
            "type": "artifact",
            "url": "packages"
        }
    ],
    "archive": {
        "directory": "packages",
        "format": "zip",
        "prefix-url": "https://my.satis.repo/packages"
    },
    "require-all": true
}
```

Don't forget to set the artifact dir in `ARTIFACTS_DIR` env variable (it must
be in `/build` directory).

## Auth with github
To be able to download packages from github, you have to set 
a token in `/composer/auth.json`. If you need it, mount the file and set 
the value of your token such as below :
```json
{
    "github-oauth": {
        "github.com": "xxxxxxxxxxxxxxx"
    }
}
```

## Workflow
### Init
First of all you need to init your satis repo with `POST /init`: 
```bash
curl -XPOST -d "name=edyan/satis&homepage=https://my.satis.repo&force=true" http://localhost:8080/init
```

### Add a repository
The method is `POST /{package/name}`
```bash
curl -XPOST -d "url=https://github.com/edyan/neuralyzer" http://localhost:8080/edyan/neuralyzer
```

You can also upload a file if you use artifacts : 
```bash
curl -XPOST -F "package=@filename.zip" http://localhost:8080/edyan/package-name
```
Each package needs a version in it, it's mandatory. To add one, you can use
jq : 
```bash
cat <<< $(cat composer.json | jq -M '. + { "version": "1.2" }') > composer.json
```


### Build your repository
To build your repo use the `GET /build` method. 
```bash
curl http://localhost:8080/build
```

There is also a `GET /build/{package/name}` to refresh a single package. 
That doesn't work with artifacts. 

### Delete a package
Finally you can also delete a package with `DELETE /{package/name}`

```bash
curl -XDELETE http://localhost:8080/edyan/neuralyzer
```

## Access Satis
Satis provides 2 endpoints : 
- `/index.html` (then http://localhost:8080/index.html)
- `/packages.json` (then http://localhost:8080/packages.json)
