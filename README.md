how to use the predefined Docker container:


docker run -d -p 8080:8080 --name panos-cloud-appid-mock swaschkut/pan-os_cloud-appid_api-helper  


Ask for a specific cloud-appid named application: 'chronosphere'
curl "http://localhost:8080/?type=op&cmd=<show><cloud-appid><application>chronosphere</application></cloud-appid></show>"


Ask for all available application ID to Name:
curl -i "http://localhost:8080/?type=op&cmd=<show><cloud-appid><cloud-app-data><application><all></all></application></cloud-app-data></cloud-appid></show>"
