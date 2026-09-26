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

Ask for all available application ID to Name:
---
```php
curl -i "http://localhost:8080/?type=op&cmd=<show><cloud-appid><cloud-app-data><application><all></all></application></cloud-app-data></cloud-appid></show>"
```



GUI Dashboard
---
```php
http://localhost:8080/dashboard.php
```

