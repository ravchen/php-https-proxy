# php-https-proxy

nginx可以正向代理转发网站，但是如果代理转发的是https的网站，则需要另外安装组件比如ngx_http_proxy_connect_module
如果不想安装这类组件，可以用nginx+php来实现正向代理

本项目只需要可以修改nginx配置，以及php5.6版本及以上即可。
