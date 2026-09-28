pan-os_cloud-appid_api-helper
==================

how to use the predefined Docker container:
---

```php
docker run -d -p 8080:80 --name panos_cloud-appid_mock swaschkut/pan-os_cloud-appid_api-helper  
```

Ask for a specific cloud-appid named application: 'chronosphere'
----
```php
curl "http://localhost:8080/?type=op&cmd=<show><cloud-appid><application>chronosphere</application></cloud-appid></show>"
```

Web-Browser:
```php
http://localhost:8080/?type=op&cmd=<show><cloud-appid><application>chronosphere</application></cloud-appid></show>
```


Ask for all available application ID to Name:
---
```php
curl -i "http://localhost:8080/?type=op&cmd=<show><cloud-appid><cloud-app-data><application><all></all></application></cloud-app-data></cloud-appid></show>"
```

Web-Browser:
```php
http://localhost:8080/?type=op&cmd=<show><cloud-appid><cloud-app-data><application><all></all></application></cloud-app-data></cloud-appid></show>
```

per-default only the first 2000 applications are returned.
```php
<application><all></all><position>2001</position><limit>2000</limit></application>
```

GUI Dashboard
---
```php
http://localhost:8080/dashboard.php
```


Process description to update the github repository with the latest APP-ID information:

1. as PAN-OS XML API is not working, log into the firewall and request manually:
    set cli pager off 
    show cloud-appid cloud-app-data application all
2. copy output into cloud-appid_new.txt
3. run script:
   [pan-os-php must be avaialble]
    php diff_sync.php in=api://MGMT-IP [of a saas inline licensed firewall]
4. right now predefined.xml must be copied out manual from pan-os-php
    docker cp <containerId>:/tool/pan-os-php/lib/object-classes/predefined.xml predefined.xml
5. generate Docker Container, via Dockerfile

