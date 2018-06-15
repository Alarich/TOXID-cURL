<?php
/**
 *    This file is part of TOXID Module for OXID eShop CE/PE/EE.
 *
 *    TOXID is free software: you can redistribute it and/or modify
 *    it under the terms of the MIT License.
 *
 *
 * @link          http://toxid.org
 * @link          http://marmalade.de
 * @package       core
 * @copyright (C) marmalade.de 2011-2013
 */

/**
 * toxid Class
 *
 */
class toxidCurl
{
    /**
     * array of content snippets
     *
     * @var array
     */
    public static $aToxidSnippets = null;
    /**
     * string with XML-content-title
     *
     * @var string
     */
    public $_sPageTitle = null;
    /**
     * string with XML-content-description
     *
     * @var string
     */
    public $_sPageDescription = null;
    /**
     * string with XML-content-keywords
     *
     * @var string
     */
    public $_sPageKeywords = null;
    /**
     * stores URL from which typo3 content is loaded.
     *
     * @var array
     */
    protected $_aSourceUrlByLang = null;
    /**
     * Stores URI to which typo3 urls will be converted, so it will be loaded in oxid
     *
     * @var array
     */
    protected $_aRewriteStartUrl = null;
    /**
     * array of string with language specific toxidUrlParam
     *
     * @var array
     */
    protected $_aToxidLangUrlParam = null;
    /**
     * array of string with admin specific toxidUrlParam
     *
     * @var array
     */
    protected $_aToxidAdminUrlParam = null;
    /**
     * stores search url by active language
     *
     * @var array
     */
    protected $_aSearchUrl = null;
    /**
     * stores search results
     *
     * @var array
     */
    protected $_aSearchCache = array();
    /**
     * stores custom page
     *
     * @var string
     */
    protected $_sCustomPage = null;
    /**
     * array of strings with language specific toxidCurlLogin
     * @var array
     */
    protected $_aToxidCurlLogin = null;

    /**
     * array of strings with language specific toxidCurlPwd
     * @var array
     */
    protected $_aToxidCurlPwd = null;
    /**
     * stores rel values for no url rewrite
     *
     * @var string
     */
    protected $_sRelValuesForNoRewrite = null;
    /**
     * stores file extension values for no url rewrite
     *
     * @var string
     */
    protected $_sFileExtensionValuesForNoRewrite = null;
    /**
     * stores not found url by active language
     *
     * @var array
     */
    protected $_aNotFoundUrl = null;
    private   $_initialized = false;
    private   $smartyParser;
    /**
     * object
     *
     * @var object
     */
    private $_oSxToxid = null;
    /**
     * string with XML-content
     *
     * @var string
     */
    private $_sPageContent = null;
    private $iLangId;
    private $cmsAvailable  = true;

    /**
     * Stores a list of URL parameters, which will be added to the CMS request.
     *
     * @var array
     */
    private $additionalUrlParams = array();

    public function init(Toxid_Curl_Smarty_Parser $smartyParser)
    {
        $this->smartyParser = $smartyParser;
        $this->_initialized = true;
    }

    public function getInitialized()
    {
        return $this->_initialized;
    }

    /**
     * returns the called snippet
     *
     * @param string $snippet
     * @param bool   $blMultiLang
     * @param string $customPage
     * @param int    $iCacheTtl
     * @param bool   $blGlobalSnippet
     *
     * @return string
     */
    public function getCmsSnippet($snippet = null, $blMultiLang = false, $customPage = null, $iCacheTtl = null, $blGlobalSnippet = false)
    {
        if (!$this->cmsAvailable) {
            return '';
        }
        if ($snippet == null) {
            return '<strong style="color:red;">TOXID: Please add part, you want to display!</strong>';
        }

        $oConf        = $this->getConfig();
        $sShopId      = $oConf->getActiveShop()->getId();
        $sLangId      = \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage();
        $oUtils       = \OxidEsales\Eshop\Core\Registry::getUtils();
        $oUtilsServer = \OxidEsales\Eshop\Core\Registry::get('oxUtilsServer');

        $sCacheIdent = $this->getCacheIdent($snippet,$sShopId,$sLangId,$blGlobalSnippet);

        // check if snippet text has a ttl and is in cache
        $iCacheTtl   = $this->getCacheLifetime($iCacheTtl);
        if ($iCacheTtl !== null && $this->_oSxToxid === null
            && ($sCacheContent = $oUtils->fromFileCache($sCacheIdent))
            && $oUtilsServer->getServerVar('HTTP_CACHE_CONTROL') !== 'no-cache'
        ) {
            return $sCacheContent;
        }

        if ($customPage != '') {
            $this->_sCustomPage = $customPage;
        }

        $sText = $this->_getSnippetFromXml($snippet);
        $sText = $this->_rewriteUrls($sText, null, $blMultiLang);

        $sPageTitle = $this->_rewriteUrls($this->_getSnippetFromXml('//metadata//title'), null, $blMultiLang);

        $sPageDescription = $this->_rewriteUrls($this->_getSnippetFromXml('//metadata//description'), null, $blMultiLang);

        $sPageKeywords = $this->_rewriteUrls($this->_getSnippetFromXml('//metadata//keywords'), null, $blMultiLang);

        $oConf   = $this->getConfig();
        $sShopId = $oConf->getActiveShop()->getId();
        $sLangId = \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage();
        $sText   = $this->smartyParser->parse($sText);

        $this->_sPageTitle = $this->smartyParser->parse($sPageTitle);

        $this->_sPageDescription = $this->smartyParser->parse($sPageDescription);

        $this->_sPageKeywords = $this->smartyParser->parse($sPageKeywords);

        /* if actual site is ssl-site, replace all image-sources with ssl-urls */
        if ($oConf->isSsl()) {

            $aSslUrl = $oConf->getShopConfVar('aToxidCurlSourceSsl', $sShopId);
            $sSslUrl = $aSslUrl[$sLangId];
            $oldSrc  = $this->_getToxidLangSource($sLangId);

            $sText = $this->replaceNonSslUrls($sText, $sSslUrl, $oldSrc);
        }

        if ($oConf->getConfigParam('iUtfMode') === 0) {
            $sText = htmlentities($sText, ENT_NOQUOTES, "UTF-8");
            $sText = html_entity_decode($sText);
        }

        // save in cache if ttl is set
        if ($iCacheTtl !== null) {
            $oUtils->toFileCache($sCacheIdent, $sText, $iCacheTtl);
        }

        return $sText;
    }

    /**
     * @param null $iLangId
     *
     * @return string with toxidStartUrl
     */
    public function getToxidStartUrl($iLangId = null)
    {
        return preg_replace('#index.php\??$#', '', $this->getConfig()->getShopHomeURL()) . $this->_getToxidLangSeoSnippet($iLangId);
    }

    /**
     * runs search by  given keywords in typo3, and returns results as html
     *
     * @param $sKeywords
     *
     * @return string
     */
    public function getSearchResult($sKeywords)
    {
        if (isset($this->_aSearchCache[$sKeywords])) {
            return $this->_aSearchCache[$sKeywords];
        }
        $this->_aSearchCache[$sKeywords] = '';
        $sSearchStartUrl                 = $this->_getToxidSearchUrl();

        $aSearchResults = $this->_getRemoteContent($sSearchStartUrl . $sKeywords);

        if ($aSearchResults['info']['http_code'] == 200) {
            $sSearchResult                   = $aSearchResults['content'];
            $sSearchResult                   = $this->_rewriteUrls($sSearchResult);
            $this->_aSearchCache[$sKeywords] = $sSearchResult;
        }

        if ($this->getConfig()->getConfigParam('iUtfMode') !== 1) {
            return utf8_decode($this->_aSearchCache[$sKeywords]);
        } else {
            return $this->_aSearchCache[$sKeywords];
        }
    }

    /**
     * @param string $key
     * @param string $value
     */
    public function setAdditionalUrlParam($key, $value)
    {
        $this->additionalUrlParams[$key] = $value;
    }

    /**
     * @param string $page
     *
     * @return string
     */
    protected function postProcessPageString($page)
    {
        if (method_exists(get_parent_class(), 'postProcessPageString')) {
            $page = parent::postProcessPageString($page);
        }

        return $page;
    }

    /**
     * returns SimpleXMLElement object from Typo3 xml
     *
     * @param bool $blReset
     *
     * @return SimpleXMLElement
     */
    protected function _getXmlObject($blReset = false)
    {
        $customPage = $this->_getToxidCustomPage();

        if (
            isset($this->_oSxToxid[$customPage]) &&
            ($this->_oSxToxid[$customPage] instanceof SimpleXMLElement) &&
            !$blReset
        ) {
            return $this->_oSxToxid[$customPage];
        }

        $xml = $this->_getXmlFromTypo3($blReset);

        if (!$this->isXml($xml)) {
            return new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<toxid/>");
        }

        $this->_oSxToxid[$customPage] = new SimpleXMLElement($xml);

        return $this->_oSxToxid[$customPage];

    }

    /**
     * Determines if we got XML from Typo3 or not.
     *
     * @param string $response
     *
     * @return bool
     */
    protected function isXml($response)
    {
        return (0 === strpos($response, '<?xml '));
    }

    /**
     * returns snippet text from xml object
     *
     * @param $sSnippet
     *
     * @return SimpleXMLElement
     */
    protected function _getSnippetFromXml($sSnippet)
    {
        $oTypo3Xml      = $this->_getXmlObject();
        $aXpathSnippets = $oTypo3Xml->xpath('//' . $sSnippet . '[1]');
        $sText          = $aXpathSnippets[0];

        return (string) $sText;

    }

    /**
     * returns raw string from typo3 CMS-page
     *
     * @param bool $blReset set to true if you want to fetch content again
     *
     * @return string
     */
    protected function _getXmlFromTypo3($blReset = false)
    {
        if ($this->_sPageContent !== null && !$blReset) {
            return $this->_sPageContent;
        }

        $sUrl = $this->buildBaseUrl();

        $aPage  = $this->getRemoteContentAndHandleStatusCodes($sUrl);

        // Especially for Wordpress-Frickel-Heinze
        // Kill everything before the <?xml
        $this->_sPageContent = preg_replace('/.*<\?xml/ms', '<?xml', $aPage['content']);

        // remove non-utf8 characters from string
        //        /
        //          (
        //            (?: [\x00-\x7F]                 # single-byte sequences   0xxxxxxx
        //            |   [\xC0-\xDF][\x80-\xBF]      # double-byte sequences   110xxxxx 10xxxxxx
        //            |   [\xE0-\xEF][\x80-\xBF]{2}   # triple-byte sequences   1110xxxx 10xxxxxx * 2
        //            |   [\xF0-\xF7][\x80-\xBF]{3}   # quadruple-byte sequence 11110xxx 10xxxxxx * 3
        //            ){1,100}                        # ...one or more times
        //          )
        //        | .                                 # anything else
        //        /x
        $regex               = '/((?:[\x00-\x7F]|[\xC0-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF7][\x80-\xBF]{3}){1,100})|./x';
        $this->_sPageContent = preg_replace($regex, '$1', $this->_sPageContent);

        return $this->_sPageContent;
    }

    /**
     * returns array with result of http get. array structure:
     * array (
     *   [content] => '<html></html>',
     *   [info] => array (
     *     [http_code] => 200,
     *     ...
     *   )
     * )
     *
     * @param $sUrl
     *
     * @return array
     */
    protected function _getRemoteContent($sUrl)
    {
        $aResult     = [];
        $curl_handle = curl_init();

        $sUrl = $this->buildRequestUrl($sUrl);

        curl_setopt($curl_handle, CURLOPT_URL, $sUrl);
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
        if (!$this->isToxidCurlPage()) {
            curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);
        }

        // Use cURL Login/Password
        $sLogin = $this->_getToxidLangCurlLogin();
        $sPwd = $this->_getToxidLangCurlPwd();
        if($sLogin.$sPwd !== '') {
            curl_setopt($curl_handle, CURLOPT_USERPWD, $sLogin.':'.$sPwd);
        }

        if ($this->getConfig()->getConfigParam('toxidDontVerifySSLCert')) {
            curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, false);
        }

        /* Forward POST requests like a boss */
        if (!empty($_POST) && !$this->getConfig()->getConfigParam('bToxidDontPassPostVarsToCms')) {
            $postRequest = http_build_query($_POST, '', '&');
            curl_setopt($curl_handle, CURLOPT_POST, 1);
            curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $postRequest);
        }

        $aResult['content'] = curl_exec($curl_handle);
        $aResult['info']    = curl_getinfo($curl_handle);
        curl_close($curl_handle);

        return $aResult;
    }

    /**
     * rewrites given string URL's, which belongs to typo3 and configured in aToxidCurlSource
     *
     * @param string $sContent
     * @param int    $iLangId
     * @param bool   $blMultiLang
     *
     * @return string with changed URL's
     */
    protected function _rewriteUrls($sContent, $iLangId = null, $blMultiLang = false)
    {
        if ($this->getConfig()->getConfigParam('toxidDontRewriteUrls') == true) {
            return $sContent;
        }

        if ($blMultiLang == false) {
            if ($iLangId === null) {
                $iLangId = \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage();
            }
            $aLanguages = array($iLangId);
        } else {
            $aLangs = $this->getConfig()->getConfigParam('aToxidCurlSource');
            arsort($aLangs);
            $aLanguages = array_keys($aLangs);
        }

        foreach ($aLanguages as $iLangId) {
            $sShopUrl = $this->getConfig()->getShopUrl();

            if (substr($sShopUrl, -1) !== '/') {
                $sShopUrl = $sShopUrl . '/';
            }
            $target  = rtrim($sShopUrl . $this->_getToxidLangSeoSnippet($iLangId), '/') . '/';
            $source  = $this->_getToxidLangSource($iLangId);
            $pattern = '%(action|href)=[\'"]' . $source . '[^"\']*?(?:/|\.html|\.php|\.asp)?(?:\?[^"\']*)?[\'"]%';

            preg_match_all($pattern, $sContent, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {

                // skip rewrite for defined file extensions
                if ($this->_getFileExtensionValuesForNoRewrite()) {
                    if (preg_match('%\.(' . $this->_getFileExtensionValuesForNoRewrite() . ')[\'"]*%i', $match[0])) {
                        continue;
                    }
                }

                if ('src' == $match[1] && $this->getConfig()->getConfigParam('toxidRewriteUrlEncoded') == true) {
                    $sContent = str_replace($match[0], str_replace(urlencode($source), urlencode($target), $match[0]), $sContent);
                    continue;
                }

                // skip rewrite for defined rel values
                if ($this->_getRelValuesForNoRewrite()) {
                    if (preg_match('%rel=["\'](' . $this->_getRelValuesForNoRewrite() . ')["\']%', $match[0])) {
                        continue;
                    }
                }

                $sContent = str_replace($match[0], str_replace($source, $target, $match[0]), $sContent);
            }
            unset($match);

        }
        
        // strip double http://example.com/http://example.com
        $sContent = preg_replace('/href=(\'|")(http[s]{0,1}:\/\/.+?)\/(http[s]{0,1}:\/\/.+?)(\'|")/', 'href=$1$2/$1', $sContent);

        return $sContent;
    }

    /**
     * returns string with language specific sourceUrl
     *
     * @param int  $iLangId
     * @param bool $blReset reset object value, and get url again
     *
     * @return string
     */
    protected function _getToxidLangSource($iLangId = null, $blReset = false)
    {
        if ($this->_aSourceUrlByLang === null || $blReset) {
            $this->_aSourceUrlByLang = $this->getConfig()->getConfigParam('aToxidCurlSource');
        }
        if ($iLangId === null) {
            $iLangId = \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage();
        }

        $source = $this->_aSourceUrlByLang[$iLangId];
        if (substr($source, -1) !== '/') {
            return $source . '/';
        }

        return $source;
    }

    /**
     * returns string with language specific toxidUrlParam
     *
     * @param int  $iLangId
     * @param bool $blReset reset object value, and get url again
     *
     * @return string
     */
    protected function _getToxidLangUrlParam($iLangId = null, $blReset = false)
    {
        if ($this->_aToxidLangUrlParam === null || $blReset) {
            $this->_aToxidLangUrlParam = $this->getConfig()->getConfigParam('aToxidCurlUrlParams');
        }
        if ($iLangId === null) {
            $iLangId = \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage();
        }

        return '?' . ltrim($this->_aToxidLangUrlParam[$iLangId], '?');
    }

    /**
     * returns string with toxid specific url params
     *
     * @return string
     */
    protected function _getToxidUrlParams()
    {
        $langParam  = $this->_getToxidLangUrlParam();

        $params = $langParam;

        if ($this->isAdminLoggedIn())
        {
            $params .= $this->_getToxidAdminUrlParam();
            $params .= $this->_getToxidCmsUrlParams();
        }

        return $params;
    }

    /**
     * returns string with admin specific toxidUrlParam
     * @return mixed|string
     * @internal param bool $param
     */
    protected function _getToxidAdminUrlParam()
    {
        $this->_aToxidAdminUrlParam = $this->getConfig()->getConfigParam('aToxidCurlUrlAdminParams');

        return '&' . ltrim($this->_aToxidAdminUrlParam[0], '?');
    }

    /**
     * returns string with cms specific url params
     * @return string
     */
    protected function _getToxidCmsUrlParams()
    {
        $params  = explode(",", $this->getConfig()->getConfigParam('toxidAllowedCmsRequestParams'));
        $cmsParams = '';

        foreach ($params as $param) {

            $paramValue = \OxidEsales\Eshop\Core\Registry::getConfig()->getRequestParameter($param);

            if (isset($paramValue) && $paramValue !== '') {
                $cmsParams .= '&' . $param . '=' . $paramValue;
            }
        }

        return $cmsParams;
    }

    /**
     * returns string with language specific toxidSeoSnippet
     *
     * @param int  $iLangId
     * @param bool $blReset reset object value, and get url again
     *
     * @return string
     */
    protected function _getToxidLangSeoSnippet($iLangId = null, $blReset = false)
    {
        if ($this->_aRewriteStartUrl === null || $blReset) {
            $this->_aRewriteStartUrl = $this->getConfig()->getConfigParam('aToxidCurlSeoSnippets');
        }
        if ($iLangId === null) {
            $iLangId = \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage();
        }

        return $this->_aRewriteStartUrl[$iLangId];
    }

    /**
     * returns string with the currently defined custom url
     */
    protected function _getToxidCustomPage()
    {
        return ($this->_sCustomPage !== null) ? $this->_sCustomPage : '';
    }

    /**
     * returns typo3 search URL
     *
     * @param int  $iLangId
     * @param bool $blReset reset object value, and get url again
     *
     * @return string
     */
    protected function _getToxidSearchUrl($iLangId = null, $blReset = false)
    {
        if ($this->_aSearchUrl === null || $blReset) {
            $this->_aSearchUrl = $this->getConfig()->getConfigParam('aToxidSearchUrl');
        }
        if ($iLangId === null) {
            $iLangId = \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage();
        }

        return $this->_aSearchUrl[$iLangId];
    }

    /**
     * returns string with rel values separated by '|'
     *
     * @return string
     */
    protected function _getRelValuesForNoRewrite()
    {
        if ($this->_sRelValuesForNoRewrite === null) {
            $this->_sRelValuesForNoRewrite = implode('|', explode(',', str_replace(' ', '', $this->getConfig()->getConfigParam('toxidDontRewriteRelUrls'))));
        }

        return $this->_sRelValuesForNoRewrite;
    }

    /**
     * returns string with rel values separated by '|'
     *
     * @return string
     */
    protected function _getFileExtensionValuesForNoRewrite()
    {
        if ($this->_sFileExtensionValuesForNoRewrite === null) {
            $this->_sFileExtensionValuesForNoRewrite = implode('|', explode(',', str_replace(' ', '', $this->getConfig()->getConfigParam('toxidDontRewriteFileExtension'))));
        }

        return $this->_sFileExtensionValuesForNoRewrite;
    }

    /**
     * returns typo3 URL for not found page
     *
     * @param int $iLangId
     * @param bool $blReset reset object value, and get url again
     *
     * @return null|string
     */
    protected function _getToxidNotFoundUrl($iLangId = null, $blReset = false)
    {
        if ($this->_aNotFoundUrl === null || $blReset) {
            $this->_aNotFoundUrl = $this->getConfig()->getConfigParam('aToxidNotFoundUrl');
        }
        if ($iLangId === null) {
            $iLangId = \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage();
        }

        return array_key_exists($iLangId, (array)$this->_aNotFoundUrl) ? $this->_aNotFoundUrl[$iLangId] : null;
    }

    /**
     * Handles HTTP status codes for toxid response
     *
     * @param $sUrl
     * @param $notFound404
     * @return array
     */
    protected function getRemoteContentAndHandleStatusCodes($sUrl, $notFound404 = false)
    {
        $aPage = $this->_getRemoteContent($sUrl);
        switch ($aPage['info']['http_code']) {
            case 500:
                header("HTTP/1.1 500 Internal Server Error");
                header('Location: ' . $this->getConfig()->getShopHomeURL());
                \OxidEsales\Eshop\Core\Registry::getUtils()->showMessageAndExit('');
                break;
            case 404:
                if($this->_getToxidNotFoundUrl() && !$notFound404) {
                    header("HTTP/1.0 404 Not Found");
                    $aPage = $this->getRemoteContentAndHandleStatusCodes($this->_getToxidNotFoundUrl(), true);
                    break;
                }
                $this->handleError(404, $aPage['info']['url']);
                break;
            case 403:
                header ("HTTP/1.1 403 Forbidden");
                header ('Location: '.$this->getConfig()->getShopHomeURL());
                \OxidEsales\Eshop\Core\Registry::getUtils()->showMessageAndExit('');
                break;
            case 401:
                header ("HTTP/1.1 401 Unauthorized");
                header ('Location: '.$this->getConfig()->getShopHomeURL());
                \OxidEsales\Eshop\Core\Registry::getUtils()->showMessageAndExit('');
                break;
            case 301:
                if ($this->getConfig()->getConfigParam('bToxidRedirect301ToStartpage')) {
                    header("HTTP/1.1 301 Moved Permanently");
                    header('Location: ' . $this->getToxidStartUrl());
                    \OxidEsales\Eshop\Core\Registry::getUtils()->showMessageAndExit('');
                } else {
                    $redirectUrl = $this->prepareRedirectUrl($aPage['info']['redirect_url']);
                    \OxidEsales\Eshop\Core\Registry::getUtils()->redirect($redirectUrl, false, 301);
                }
                break;
            case 302:
            case 303:
            case 307:
                $redirectUrl = $aPage['info']['redirect_url'];
                $aPage       = $this->getRemoteContentAndHandleStatusCodes($redirectUrl);
                break;
            case 0:
                header('Location: ' . $this->getConfig()->getShopHomeURL());
                \OxidEsales\Eshop\Core\Registry::getUtils()->showMessageAndExit('');
                break;
        }

        return $aPage;
    }

    /**
     * Prepares Url for redirect in shop
     *
     * @param $sUrl
     *
     * @return string
     */
    private function prepareRedirectUrl($sUrl)
    {
        $aLangSource = $this->getConfig()->getConfigParam('aToxidCurlSource');
        $iLangId     = $this->getBaseLanguage();
        $source      = $aLangSource[$iLangId];
        $target      = $this->_getToxidLangSeoSnippet($iLangId);
        // replace domain
        $sUrl = str_replace($source, $target, $sUrl);

        return $sUrl;
    }

    /**
     * Check if current page is a toxid page or if toxid is used for snippets (i.e. navigation) on the current page
     *
     * @return bool
     */
    private function isToxidCurlPage()
    {
        return 'toxid_curl' == $this->getConfig()->getActiveView()->getClassName();
    }

    /**
     * Getter for base language
     *
     * @return integer
     */
    private function getBaseLanguage()
    {
        if ($this->iLangId === null) {
            $this->iLangId = \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage();
        }

        return $this->iLangId;
    }

    /**
     * Replaces Links to SSL-Links if configured.
     *
     * @param string $sText
     * @param string $sSslUrl
     * @param string $oldSrc
     *
     * @return string
     */
    private function replaceNonSslUrls($sText, $sSslUrl, $oldSrc)
    {
        if (!empty($sSslUrl)) {

            $newSrc = $sSslUrl;

            if ($oldSrc != "" && $newSrc != "") {
                $sText = str_replace('src="' . $oldSrc, 'src="' . $newSrc, $sText);
            }
        }

        return $sText;
    }

    /**
     * Getter for oxConfig
     *
     * @return oxConfig
     */
    private function getConfig()
    {
        return \OxidEsales\Eshop\Core\Registry::getConfig();
    }

    /**
     * Handle toxid request errors
     *
     * @param integer $statusCode
     * @param string  $sUrl
     */
    protected function handleError($statusCode, $sUrl = '')
    {
        $this->cmsAvailable = false;

        switch ($statusCode) {
            case 404:
                $sRedirectLinkError404 = $this->getConfig()->getConfigParam('toxidError404Link');
                if ('' !== trim($sRedirectLinkError404)) {
                    \OxidEsales\Eshop\Core\Registry::getUtils()->redirect($sRedirectLinkError404);
                }
                \OxidEsales\Eshop\Core\Registry::getUtils()->handlePageNotFoundError($sUrl);
                break;
        }

    }

    private function getCacheLifetime($iCacheTtl)
    {
        if (null === $iCacheTtl) {
            $defaultCacheTtl = $this->getConfig()->getConfigParam('toxidCacheTtl');
            if ('' !== trim($defaultCacheTtl)) {
                $iCacheTtl = $defaultCacheTtl;
            }
        }

        return $iCacheTtl;
    }

    private function getCacheIdent($snippet, $sShopId, $sLangId, $blGlobalSnippet)
    {
        $identString = $snippet . $this->buildRequestUrl($this->buildBaseUrl());

        if (!$blGlobalSnippet) {
            $identString .= $this->getConfig()->getConfigParam('sToxidCurlPage');
        }

        $identHash = md5($identString);

        return "toxid_snippet_{$identHash}_{$sShopId}_{$sLangId}";
    }

    /**
     * @return bool
     */
    private function isAdminLoggedIn()
    {
        $user = \OxidEsales\Eshop\Core\Registry::getConfig()->getUser();

        return ($user && $user->oxuser__oxrights->value != 'user');
    }

    /**
     * @param $baseUrl
     *
     * @return string
     */
    protected function buildRequestUrl($baseUrl)
    {
        $params = http_build_query($this->additionalUrlParams);
        if (false === strpos($baseUrl, '?')) {
            $baseUrl .= "?{$params}";
        } else {
            $baseUrl = rtrim($baseUrl, '&') . "&{$params}";
        }

        $baseUrl = rtrim($baseUrl, '&?');

        return $baseUrl;
    }

    /**
     * @return string
     */
    protected function buildBaseUrl()
    {
        $source = $this->_getToxidLangSource();
        $page   = $this->getConfig()->getConfigParam('sToxidCurlPage');
        $page   = $this->postProcessPageString($page);
        $custom = $this->_getToxidCustomPage();
        $params = $this->_getToxidUrlParams();

        $sUrl = $source . $custom . $page . $params;

        return $sUrl;
    }

    /**
     * Get language specific Toxid access control login
     * @param int $iLangId
     * @param bool $blReset reset object value, and get url again
     * @return string
     */
    protected function _getToxidLangCurlLogin($iLangId = null, $blReset = false)
    {
        $oConf   = \OxidEsales\Eshop\Core\Registry::getConfig();
        if ($this->_aToxidCurlLogin === null || $blReset) {
            $this->_aToxidCurlLogin = $oConf->getConfigParam('aToxidCurlLogin');
        }
        if ($iLangId === null) {
            $iLangId = \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage();
        }

        return isset($this->_aToxidCurlLogin[$iLangId]) ? $this->_aToxidCurlLogin[$iLangId] : '';
    }

    /**
     * Get language specific Toxid access control password
     * @param int $iLangId
     * @param bool $blReset reset object value, and get url again
     * @return string
     */
    protected function _getToxidLangCurlPwd($iLangId = null, $blReset = false)
    {
        $oConf   = \OxidEsales\Eshop\Core\Registry::getConfig();
        if ($this->_aToxidCurlPwd === null || $blReset) {

            $oDecryptor = oxNew(\OxidEsales\Eshop\Core\Decryptor::class);
            $encryptKey = $oConf->getConfigParam('dbPwd');
            $this->_aToxidCurlPwd = $oConf->getConfigParam('aToxidCurlPwd');

            foreach($this->_aToxidCurlPwd as $lang => $value) {
                if($value !== '') {
                    $this->_aToxidCurlPwd[$lang] = $oDecryptor->decrypt($value, $encryptKey);
                }
            }
        }
        if ($iLangId === null) {
            $iLangId = \OxidEsales\Eshop\Core\Registry::getLang()->getBaseLanguage();
        }

        return isset($this->_aToxidCurlPwd[$iLangId]) ? $this->_aToxidCurlPwd[$iLangId] : '';
    }
}
