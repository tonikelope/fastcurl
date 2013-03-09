There are two classes: one for sending regular HTTP requests and another to send multiple parallel requests (first includes later).

SSL is supported by default just using a url with 'https://'

Every CURLOPT can be set with a class variable assignment. ($fc->url = 'http://github.com')

It has its own cookies engine for set, edit or delete any cookie (it also supports cURL default cookies).
