<?php

namespace PhpXmlRpc;

use PhpXmlRpc\Helper\XMLParser;
use PhpXmlRpc\Helper\Charset;

class Server
{
    /**
     * Array defining php functions exposed as xmlrpc methods by this server.
     */
    protected $dmap = array();
    /*
    * Defines how functions in dmap will be invoked: either using an xmlrpc msg object
    * or plain php values.
    * valid strings are 'xmlrpcvals', 'phpvals' or 'epivals'
    */
    public $functions_parameters_type = 'xmlrpcvals';
    /*
    * Option used for fine-tuning the encoding the php values returned from
    * functions registered in the dispatch map when the functions_parameters_types
    * member is set to 'phpvals'
    * @see php_xmlrpc_encode for a list of values
    */
    public $phpvals_encoding_options = array('auto_dates');
    /// controls whether the server is going to echo debugging messages back to the client as comments in response body. valid values: 0,1,2,3
    public $debug = 1;
    /*
    * Controls behaviour of server when invoked user function throws an exception:
    * 0 = catch it and return an 'internal error' xmlrpc response (default)
    * 1 = catch it and return an xmlrpc response with the error corresponding to the exception
    * 2 = allow the exception to float to the upper layers
    */
    public $exception_handling = 0;
    /*
    * When set to true, it will enable HTTP compression of the response, in case
    * the client has declared its support for compression in the request.
    */
    public $compress_response = false;
    /*
    * List of http compression methods accepted by the server for requests.
    * NB: PHP supports deflate, gzip compressions out of the box if compiled w. zlib
    */
    public $accepted_compression = array();
    /// shall we serve calls to system.* methods?
    public $allow_system_funcs = true;
    /// list of charset encodings natively accepted for requests
    public $accepted_charset_encodings = array();
    /*
    * charset encoding to be used for response.
    * NB: if we can, we will convert the generated response from internal_encoding to the intended one.
    * can be: a supported xml encoding (only UTF-8 and ISO-8859-1 at present, unless mbstring is enabled),
    * null (leave unspecified in response, convert output stream to US_ASCII),
    * 'default' (use xmlrpc library default as specified in xmlrpc.inc, convert output stream if needed),
    * or 'auto' (use client-specified charset encoding or same as request if request headers do not specify it (unless request is US-ASCII: then use library default anyway).
    * NB: pretty dangerous if you accept every charset and do not have mbstring enabled)
    */
    public $response_charset_encoding = '';
    /**
     * Storage for internal debug info.
     */
    protected $debug_info = '';
    /*
    * Extra data passed at runtime to method handling functions. Used only by EPI layer
    */
    public $user_data = null;

    protected static $_xmlrpc_debuginfo = '';
    protected static $_xmlrpcs_occurred_errors = '';
    public static $_xmlrpcs_prev_ehandler = '';

    /**
     * @param array $dispatchMap the dispatch map with definition of exposed services
     * @param boolean $servicenow set to false to prevent the server from running upon construction
     */
    public function __construct($dispatchMap = null, $serviceNow = true)
    {
        // if ZLIB is enabled, let the server by default accept compressed requests,
        // and compress responses sent to clients that support them
        if (function_exists('gzinflate')) {
            $this->accepted_compression = array('gzip', 'deflate');
            $this->compress_response = true;
        }

        // by default the xml parser can support these 3 charset encodings
        $this->accepted_charset_encodings = array('UTF-8', 'ISO-8859-1', 'US-ASCII');

        // dispMap is a dispatch array of methods
        // mapped to function names and signatures
        // if a method
        // doesn't appear in the map then an unknown
        // method error is generated
        /* milosch - changed to make passing dispMap optional.
            * instead, you can use the class add_to_map() function
            * to add functions manually (borrowed from SOAPX4)
            */
        if ($dispatchMap) {
            $this->dmap = $dispatchMap;
            if ($serviceNow) {
                $this->service();
            }
        }
    }

    /**
     * Set debug level of server.
     *
     * @param integer $level debug lvl: determines info added to xmlrpc responses (as xml comments)
     *                    0 = no debug info,
     *                    1 = msgs set from user with debugmsg(),
     *                    2 = add complete xmlrpc request (headers and body),
     *                    3 = add also all processing warnings happened during method processing
     *                    (NB: this involves setting a custom error handler, and might interfere
     *                    with the standard processing of the php function exposed as method. In
     *                    particular, triggering an USER_ERROR level error will not halt script
     *                    execution anymore, but just end up logged in the xmlrpc response)
     *                    Note that info added at level 2 and 3 will be base64 encoded
     */
    public function setDebug($level)
    {
        $this->debug = $level;
    }

    /**
     * Add a string to the debug info that can be later serialized by the server
     * as part of the response message.
     * Note that for best compatibility, the debug string should be encoded using
     * the PhpXmlRpc::$xmlrpc_internalencoding character set.
     *
     * @param string $msg
     * @access public
     */
    public static function xmlrpc_debugmsg($msg)
    {
        static::$_xmlrpc_debuginfo .= $msg . "\n";
    }

    public static function error_occurred($msg)
    {
        static::$_xmlrpcs_occurred_errors .= $msg . "\n";
    }

    /**
     * Return a string with the serialized representation of all debug info.
     *
     * @param string $charsetEncoding the target charset encoding for the serialization
     *
     * @return string an XML comment (or two)
     */
    public function serializeDebug($charsetEncoding = '')
    {
        // Tough encoding problem: which internal charset should we assume for debug info?
        // It might contain a copy of raw data received from client, ie with unknown encoding,
        // intermixed with php generated data and user generated data...
        // so we split it: system debug is base 64 encoded,
        // user debug info should be encoded by the end user using the INTERNAL_ENCODING
        $out = '';
        if ($this->debug_info != '') {
            $out .= "<!-- SERVER DEBUG INFO (BASE64 ENCODED):\n" . base64_encode($this->debug_info) . "\n-->\n";
        }
        if (static::$_xmlrpc_debuginfo != '') {
            $out .= "<!-- DEBUG INFO:\n" . Charset::instance()->encodeEntities(str_replace('--', '_-', static::$_xmlrpc_debuginfo), PhpXmlRpc::$xmlrpc_internalencoding, $charsetEncoding) . "\n-->\n";
            // NB: a better solution MIGHT be to use CDATA, but we need to insert it
            // into return payload AFTER the beginning tag
            //$out .= "<![CDATA[ DEBUG INFO:\n\n" . str_replace(']]>', ']_]_>', static::$_xmlrpc_debuginfo) . "\n]]>\n";
        }

        return $out;
    }

    /**
     * Execute the xmlrpc request, printing the response.
     *
     * @param string $data the request body. If null, the http POST request will be examined
     * @param bool $returnPayload When true, return the response but do not echo it or any http header
     *
     * @return Response the response object (usually not used by caller...)
     */
    public function service($data = null, $returnPayload = false)
    {
        if ($data === null) {
            $data = file_get_contents('php://input');
        }
        $rawData = $data;

        // reset internal debug info
        $this->debug_info = '';

        // Echo back what we received, before parsing it
        if ($this->debug > 1) {
            $this->debugmsg("+++GOT+++\n" . $data . "\n+++END+++");
        }

        $r = $this->parseRequestHeaders($data, $reqCharset, $respCharset, $respEncoding);
        if (!$r) {
            $r = $this->parseRequest($data, $reqCharset);
        }

        // save full body of request into response, for more debugging usages
        $r->raw_data = $rawData;

        if ($this->debug > 2 && static::$_xmlrpcs_occurred_errors) {
            $this->debugmsg("+++PROCESSING ERRORS AND WARNINGS+++\n" .
                static::$_xmlrpcs_occurred_errors . "+++END+++");
        }

        $payload = $this->xml_header($respCharset);
        if ($this->debug > 0) {
            $payload = $payload . $this->serializeDebug($respCharset);
        }

        // G. Giunta 2006-01-27: do not create response serialization if it has
        // already happened. Helps building json magic
        if (empty($r->payload)) {
            $r->serialize($respCharset);
        }
        $payload = $payload . $r->payload;

        if ($returnPayload) {
            return $payload;
        }

        // if we get a warning/error that has output some text before here, then we cannot
        // add a new header. We cannot say we are sending xml, either...
        if (!headers_sent()) {
            header('Content-Type: ' . $r->content_type);
            // we do not know if client actually told us an accepted charset, but if he did
            // we have to tell him what we did
            header("Vary: Accept-Charset");

            // http compression of output: only
            // if we can do it, and we want to do it, and client asked us to,
            // and php ini settings do not force it already
            $phpNoSelfCompress = !ini_get('zlib.output_compression') && (ini_get('output_handler') != 'ob_gzhandler');
            if ($this->compress_response && function_exists('gzencode') && $respEncoding != ''
                && $phpNoSelfCompress
            ) {
                if (strpos($respEncoding, 'gzip') !== false) {
                    $payload = gzencode($payload);
                    header("Content-Encoding: gzip");
                    header("Vary: Accept-Encoding");
                } elseif (strpos($respEncoding, 'deflate') !== false) {
                    $payload = gzcompress($payload);
                    header("Content-Encoding: deflate");
                    header("Vary: Accept-Encoding");
                }
            }

            // do not output content-length header if php is compressing output for us:
            // it will mess up measurements
            if ($phpNoSelfCompress) {
                header('Content-Length: ' . (int)strlen($payload));
            }
        } else {
            error_log('XML-RPC: ' . __METHOD__ . ': http headers already sent before response is fully generated. Check for php warning or error messages');
        }

        print $payload;

        // return request, in case subclasses want it
        return $r;
    }

    /**
     * Add a method to the dispatch map.
     *
     * @param string $methodName the name with which the method will be made available
     * @param string $function the php function that will get invoked
     * @param array $sig the array of valid method signatures
     * @param string $doc method documentation
     * @param array $sigDoc the array of valid method signatures docs (one string per param, one for return type)
     */
    public function add_to_map($methodName, $function, $sig = null, $doc = false, $sigDoc = false)
    {
        $this->dmap[$methodName] = array(
            'function' => $function,
            'docstring' => $doc,
        );
        if ($sig) {
            $this->dmap[$methodName]['signature'] = $sig;
        }
        if ($sigDoc) {
            $this->dmap[$methodName]['signature_docs'] = $sigDoc;
        }
    }

    /**
     * Verify type and number of parameters received against a list of known signatures.
     *
     * @param array $in array of either xmlrpc value objects or xmlrpc type definitions
     * @param array $sig array of known signatures to match against
     *
     * @return array
     */
    protected function verifySignature($in, $sig)
    {
        // check each possible signature in turn
        if (is_object($in)) {
            $numParams = $in->getNumParams();
        } else {
            $numParams = count($in);
        }
        foreach ($sig as $cursig) {
            if (count($cursig) == $numParams + 1) {
                $itsOK = 1;
                for ($n = 0; $n < $numParams; $n++) {
                    if (is_object($in)) {
                        $p = $in->getParam($n);
                        if ($p->kindOf() == 'scalar') {
                            $pt = $p->scalartyp();
                        } else {
                            $pt = $p->kindOf();
                        }
                    } else {
                        $pt = $in[$n] == 'i4' ? 'int' : strtolower($in[$n]); // dispatch maps never use i4...
                    }

                    // param index is $n+1, as first member of sig is return type
                    if ($pt != $cursig[$n + 1] && $cursig[$n + 1] != Value::$xmlrpcValue) {
                        $itsOK = 0;
                        $pno = $n + 1;
                        $wanted = $cursig[$n + 1];
                        $got = $pt;
                        break;
                    }
                }
                if ($itsOK) {
                    return array(1, '');
                }
            }
        }
        if (isset($wanted)) {
            return array(0, "Wanted ${wanted}, got ${got} at param ${pno}");
        } else {
            return array(0, "No method signature matches number of parameters");
        }
    }

    /**
     * Parse http headers received along with xmlrpc request. If needed, inflate request.
     *
     * @return mixed null on success or a Response
     */
    protected function parseRequestHeaders(&$data, &$reqEncoding, &$respEncoding, &$respCompression)
    {
        // check if $_SERVER is populated: it might have been disabled via ini file
        // (this is true even when in CLI mode)
        if (count($_SERVER) == 0) {
            error_log('XML-RPC: ' . __METHOD__ . ': cannot parse request headers as $_SERVER is not populated');
        }

        if ($this->debug > 1) {
            if (function_exists('getallheaders')) {
                $this->debugmsg(''); // empty line
                foreach (getallheaders() as $name => $val) {
                    $this->debugmsg("HEADER: $name: $val");
                }
            }
        }

        if (isset($_SERVER['HTTP_CONTENT_ENCODING'])) {
            $contentEncoding = str_replace('x-', '', $_SERVER['HTTP_CONTENT_ENCODING']);
        } else {
            $contentEncoding = '';
        }

        // check if request body has been compressed and decompress it
        if ($contentEncoding != '' && strlen($data)) {
            if ($contentEncoding == 'deflate' || $contentEncoding == 'gzip') {
                // if decoding works, use it. else assume data wasn't gzencoded
                if (function_exists('gzinflate') && in_array($contentEncoding, $this->accepted_compression)) {
                    if ($contentEncoding == 'deflate' && $degzdata = @gzuncompress($data)) {
                        $data = $degzdata;
                        if ($this->debug > 1) {
                            $this->debugmsg("\n+++INFLATED REQUEST+++[" . strlen($data) . " chars]+++\n" . $data . "\n+++END+++");
                        }
                    } elseif ($contentEncoding == 'gzip' && $degzdata = @gzinflate(substr($data, 10))) {
                        $data = $degzdata;
                        if ($this->debug > 1) {
                            $this->debugmsg("+++INFLATED REQUEST+++[" . strlen($data) . " chars]+++\n" . $data . "\n+++END+++");
                        }
                    } else {
                        $r = new Response(0, PhpXmlRpc::$xmlrpcerr['server_decompress_fail'], PhpXmlRpc::$xmlrpcstr['server_decompress_fail']);

                        return $r;
                    }
                } else {
                    //error_log('The server sent deflated data. Your php install must have the Zlib extension compiled in to support this.');
                    $r = new Response(0, PhpXmlRpc::$xmlrpcerr['server_cannot_decompress'], PhpXmlRpc::$xmlrpcstr['server_cannot_decompress']);

                    return $r;
                }
            }
        }

        // check if client specified accepted charsets, and if we know how to fulfill
        // the request
        if ($this->response_charset_encoding == 'auto') {
            $respEncoding = '';
            if (isset($_SERVER['HTTP_ACCEPT_CHARSET'])) {
                // here we should check if we can match the client-requested encoding
                // with the encodings we know we can generate.
                /// @todo we should parse q=0.x preferences instead of getting first charset specified...
                $clientAcceptedCharsets = explode(',', strtoupper($_SERVER['HTTP_ACCEPT_CHARSET']));
                // Give preference to internal encoding
                $knownCharsets = array(PhpXmlRpc::$xmlrpc_internalencoding, 'UTF-8', 'ISO-8859-1', 'US-ASCII');
                foreach ($knownCharsets as $charset) {
                    foreach ($clientAcceptedCharsets as $accepted) {
                        if (strpos($accepted, $charset) === 0) {
                            $respEncoding = $charset;
                            break;
                        }
                    }
                    if ($respEncoding) {
                        break;
                    }
                }
            }
        } else {
            $respEncoding = $this->response_charset_encoding;
        }

        if (isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            $respCompression = $_SERVER['HTTP_ACCEPT_ENCODING'];
        } else {
            $respCompression = '';
        }

        // 'guestimate' request encoding
        /// @todo check if mbstring is enabled and automagic input conversion is on: it might mingle with this check???
        $reqEncoding = XMLParser::guessEncoding(isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
            $data);

        return;
    }

    /**
     * Parse an xml chunk containing an xmlrpc request and execute the corresponding
     * php function registered with the server.
     *
     * @param string $data the xml request
     * @param string $reqEncoding (optional) the charset encoding of the xml request
     *
     * @return Response
     */
    public function parseRequest($data, $reqEncoding = '')
    {
        // decompose incoming XML into request structure

        if ($reqEncoding != '') {
            // Since parsing will fail if charset is not specified in the xml prologue,
            // the encoding is not UTF8 and there are non-ascii chars in the text, we try to work round that...
            // The following code might be better for mb_string enabled installs, but
            // makes the lib about 200% slower...
            //if (!is_valid_charset($reqEncoding, array('UTF-8')))
            if (!in_array($reqEncoding, array('UTF-8', 'US-ASCII')) && !XMLParser::hasEncoding($data)) {
                if ($reqEncoding == 'ISO-8859-1') {
                    $data = utf8_encode($data);
                } else {
                    if (extension_loaded('mbstring')) {
                        $data = mb_convert_encoding($data, 'UTF-8', $reqEncoding);
                    } else {
                        error_log('XML-RPC: ' . __METHOD__ . ': invalid charset encoding of received request: ' . $reqEncoding);
                    }
                }
            }
        }

        $parser = xml_parser_create();
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, true);
        // G. Giunta 2005/02/13: PHP internally uses ISO-8859-1, so we have to tell
        // the xml parser to give us back data in the expected charset
        // What if internal encoding is not in one of the 3 allowed?
        // we use the broadest one, ie. utf8
        // This allows to send data which is native in various charset,
        // by extending xmlrpc_encode_entities() and setting xmlrpc_internalencoding
        if (!in_array(PhpXmlRpc::$xmlrpc_internalencoding, array('UTF-8', 'ISO-8859-1', 'US-ASCII'))) {
            xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, 'UTF-8');
        } else {
            xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, PhpXmlRpc::$xmlrpc_internalencoding);
        }

        $xmlRpcParser = new XMLParser();
        xml_set_object($parser, $xmlRpcParser);

        if ($this->functions_parameters_type != 'xmlrpcvals') {
            xml_set_element_handler($parser, 'xmlrpc_se', 'xmlrpc_ee_fast');
        } else {
            xml_set_element_handler($parser, 'xmlrpc_se', 'xmlrpc_ee');
        }
        xml_set_character_data_handler($parser, 'xmlrpc_cd');
        xml_set_default_handler($parser, 'xmlrpc_dh');
        if (!xml_parse($parser, $data, 1)) {
            // return XML error as a faultCode
            $r = new Response(0,
                PhpXmlRpc::$xmlrpcerrxml + xml_get_error_code($parser),
                sprintf('XML error: %s at line %d, column %d',
                    xml_error_string(xml_get_error_code($parser)),
                    xml_get_current_line_number($parser), xml_get_current_column_number($parser)));
            xml_parser_free($parser);
        } elseif ($xmlRpcParser->_xh['isf']) {
            xml_parser_free($parser);
            $r = new Response(0,
                PhpXmlRpc::$xmlrpcerr['invalid_request'],
                PhpXmlRpc::$xmlrpcstr['invalid_request'] . ' ' . $xmlRpcParser->_xh['isf_reason']);
        } else {
            xml_parser_free($parser);
            // small layering violation in favor of speed and memory usage:
            // we should allow the 'execute' method handle this, but in the
            // most common scenario (xmlrpc values type server with some methods
            // registered as phpvals) that would mean a useless encode+decode pass
            if ($this->functions_parameters_type != 'xmlrpcvals' || (isset($this->dmap[$xmlRpcParser->_xh['method']]['parameters_type']) && ($this->dmap[$xmlRpcParser->_xh['method']]['parameters_type'] == 'phpvals'))) {
                if ($this->debug > 1) {
                    $this->debugmsg("\n+++PARSED+++\n" . var_export($xmlRpcParser->_xh['params'], true) . "\n+++END+++");
                }
                $r = $this->execute($xmlRpcParser->_xh['method'], $xmlRpcParser->_xh['params'], $xmlRpcParser->_xh['pt']);
            } else {
                // build a Request object with data parsed from xml
                $m = new Request($xmlRpcParser->_xh['method']);
                // now add parameters in
                for ($i = 0; $i < count($xmlRpcParser->_xh['params']); $i++) {
                    $m->addParam($xmlRpcParser->_xh['params'][$i]);
                }

                if ($this->debug > 1) {
                    $this->debugmsg("\n+++PARSED+++\n" . var_export($m, true) . "\n+++END+++");
                }
                $r = $this->execute($m);
            }
        }

        return $r;
    }

    /**
     * Execute a method invoked by the client, checking parameters used.
     *
     * @param mixed $m either a Request obj or a method name
     * @param array $params array with method parameters as php types (if m is method name only)
     * @param array $paramTypes array with xmlrpc types of method parameters (if m is method name only)
     *
     * @return Response
     */
    protected function execute($m, $params = null, $paramTypes = null)
    {
        if (is_object($m)) {
            $methName = $m->method();
        } else {
            $methName = $m;
        }
        $sysCall = $this->allow_system_funcs && (strpos($methName, "system.") === 0);
        $dmap = $sysCall ? $this->getSystemDispatchMap() : $this->dmap;

        if (!isset($dmap[$methName]['function'])) {
            // No such method
            return new Response(0,
                PhpXmlRpc::$xmlrpcerr['unknown_method'],
                PhpXmlRpc::$xmlrpcstr['unknown_method']);
        }

        // Check signature
        if (isset($dmap[$methName]['signature'])) {
            $sig = $dmap[$methName]['signature'];
            if (is_object($m)) {
                list($ok, $errStr) = $this->verifySignature($m, $sig);
            } else {
                list($ok, $errStr) = $this->verifySignature($paramTypes, $sig);
            }
            if (!$ok) {
                // Didn't match.
                return new Response(
                    0,
                    PhpXmlRpc::$xmlrpcerr['incorrect_params'],
                    PhpXmlRpc::$xmlrpcstr['incorrect_params'] . ": ${errstr}"
                );
            }
        }

        $func = $dmap[$methName]['function'];
        // let the 'class::function' syntax be accepted in dispatch maps
        if (is_string($func) && strpos($func, '::')) {
            $func = explode('::', $func);
        }
        // verify that function to be invoked is in fact callable
        if (!is_callable($func)) {
            error_log("XML-RPC: " . __METHOD__ . ": function $func registered as method handler is not callable");

            return new Response(
                0,
                PhpXmlRpc::$xmlrpcerr['server_error'],
                PhpXmlRpc::$xmlrpcstr['server_error'] . ": no function matches method"
            );
        }

        // If debug level is 3, we should catch all errors generated during
        // processing of user function, and log them as part of response
        if ($this->debug > 2) {
            $GLOBALS['_xmlrpcs_prev_ehandler'] = set_error_handler(array('\PhpXmlRpc\Server', '_xmlrpcs_errorHandler'));
        }
        try {
            // Allow mixed-convention servers
            if (is_object($m)) {
                if ($sysCall) {
                    $r = call_user_func($func, $this, $m);
                } else {
                    $r = call_user_func($func, $m);
                }
                if (!is_a($r, 'PhpXmlRpc\Response')) {
                    error_log("XML-RPC: " . __METHOD__ . ": function $func registered as method handler does not return an xmlrpc response object");
                    if (is_a($r, 'PhpXmlRpc\Value')) {
                        $r = new Response($r);
                    } else {
                        $r = new Response(
                            0,
                            PhpXmlRpc::$xmlrpcerr['server_error'],
                            PhpXmlRpc::$xmlrpcstr['server_error'] . ": function does not return xmlrpc response object"
                        );
                    }
                }
            } else {
                // call a 'plain php' function
                if ($sysCall) {
                    array_unshift($params, $this);
                    $r = call_user_func_array($func, $params);
                } else {
                    // 3rd API convention for method-handling functions: EPI-style
                    if ($this->functions_parameters_type == 'epivals') {
                        $r = call_user_func_array($func, array($methName, $params, $this->user_data));
                        // mimic EPI behaviour: if we get an array that looks like an error, make it
                        // an eror response
                        if (is_array($r) && array_key_exists('faultCode', $r) && array_key_exists('faultString', $r)) {
                            $r = new Response(0, (integer)$r['faultCode'], (string)$r['faultString']);
                        } else {
                            // functions using EPI api should NOT return resp objects,
                            // so make sure we encode the return type correctly
                            $r = new Response(php_xmlrpc_encode($r, array('extension_api')));
                        }
                    } else {
                        $r = call_user_func_array($func, $params);
                    }
                }
                // the return type can be either a Response object or a plain php value...
                if (!is_a($r, '\PhpXmlRpc\Response')) {
                    // what should we assume here about automatic encoding of datetimes
                    // and php classes instances???
                    $r = new Response(php_xmlrpc_encode($r, $this->phpvals_encoding_options));
                }
            }
        } catch (\Exception $e) {
            // (barring errors in the lib) an uncatched exception happened
            // in the called function, we wrap it in a proper error-response
            switch ($this->exception_handling) {
                case 2:
                    throw $e;
                    break;
                case 1:
                    $r = new Response(0, $e->getCode(), $e->getMessage());
                    break;
                default:
                    $r = new Response(0, PhpXmlRpc::$xmlrpcerr['server_error'], PhpXmlRpc::$xmlrpcstr['server_error']);
            }
        }
        if ($this->debug > 2) {
            // note: restore the error handler we found before calling the
            // user func, even if it has been changed inside the func itself
            if ($GLOBALS['_xmlrpcs_prev_ehandler']) {
                set_error_handler($GLOBALS['_xmlrpcs_prev_ehandler']);
            } else {
                restore_error_handler();
            }
        }

        return $r;
    }

    /**
     * add a string to the 'internal debug message' (separate from 'user debug message').
     *
     * @param string $string
     */
    protected function debugmsg($string)
    {
        $this->debug_info .= $string . "\n";
    }

    protected function xml_header($charsetEncoding = '')
    {
        if ($charsetEncoding != '') {
            return "<?xml version=\"1.0\" encoding=\"$charsetEncoding\"?" . ">\n";
        } else {
            return "<?xml version=\"1.0\"?" . ">\n";
        }
    }

    /* Functions that implement system.XXX methods of xmlrpc servers */

    public function getSystemDispatchMap()
    {
        return array(
            'system.listMethods' => array(
                'function' => 'PhpXmlRpc\Server::_xmlrpcs_listMethods',
                // listMethods: signature was either a string, or nothing.
                // The useless string variant has been removed
                'signature' => array(array(Value::$xmlrpcArray)),
                'docstring' => 'This method lists all the methods that the XML-RPC server knows how to dispatch',
                'signature_docs' => array(array('list of method names')),
            ),
            'system.methodHelp' => array(
                'function' => 'PhpXmlRpc\Server::_xmlrpcs_methodHelp',
                'signature' => array(array(Value::$xmlrpcString, Value::$xmlrpcString)),
                'docstring' => 'Returns help text if defined for the method passed, otherwise returns an empty string',
                'signature_docs' => array(array('method description', 'name of the method to be described')),
            ),
            'system.methodSignature' => array(
                'function' => 'PhpXmlRpc\Server::_xmlrpcs_methodSignature',
                'signature' => array(array(Value::$xmlrpcArray, Value::$xmlrpcString)),
                'docstring' => 'Returns an array of known signatures (an array of arrays) for the method name passed. If no signatures are known, returns a none-array (test for type != array to detect missing signature)',
                'signature_docs' => array(array('list of known signatures, each sig being an array of xmlrpc type names', 'name of method to be described')),
            ),
            'system.multicall' => array(
                'function' => 'PhpXmlRpc\Server::_xmlrpcs_multicall',
                'signature' => array(array(Value::$xmlrpcArray, Value::$xmlrpcArray)),
                'docstring' => 'Boxcar multiple RPC calls in one request. See http://www.xmlrpc.com/discuss/msgReader$1208 for details',
                'signature_docs' => array(array('list of response structs, where each struct has the usual members', 'list of calls, with each call being represented as a struct, with members "methodname" and "params"')),
            ),
            'system.getCapabilities' => array(
                'function' => 'PhpXmlRpc\Server::_xmlrpcs_getCapabilities',
                'signature' => array(array(Value::$xmlrpcStruct)),
                'docstring' => 'This method lists all the capabilites that the XML-RPC server has: the (more or less standard) extensions to the xmlrpc spec that it adheres to',
                'signature_docs' => array(array('list of capabilities, described as structs with a version number and url for the spec')),
            ),
        );
    }

    public static function _xmlrpcs_getCapabilities($server, $m = null)
    {
        $outAr = array(
            // xmlrpc spec: always supported
            'xmlrpc' => new Value(array(
                'specUrl' => new Value('http://www.xmlrpc.com/spec', 'string'),
                'specVersion' => new Value(1, 'int'),
            ), 'struct'),
            // if we support system.xxx functions, we always support multicall, too...
            // Note that, as of 2006/09/17, the following URL does not respond anymore
            'system.multicall' => new Value(array(
                'specUrl' => new Value('http://www.xmlrpc.com/discuss/msgReader$1208', 'string'),
                'specVersion' => new Value(1, 'int'),
            ), 'struct'),
            // introspection: version 2! we support 'mixed', too
            'introspection' => new Value(array(
                'specUrl' => new Value('http://phpxmlrpc.sourceforge.net/doc-2/ch10.html', 'string'),
                'specVersion' => new Value(2, 'int'),
            ), 'struct'),
        );

        // NIL extension
        if (PhpXmlRpc::$xmlrpc_null_extension) {
            $outAr['nil'] = new Value(array(
                'specUrl' => new Value('http://www.ontosys.com/xml-rpc/extensions.php', 'string'),
                'specVersion' => new Value(1, 'int'),
            ), 'struct');
        }

        return new Response(new Value($outAr, 'struct'));
    }

    public static function _xmlrpcs_listMethods($server, $m = null) // if called in plain php values mode, second param is missing
    {
        $outAr = array();
        foreach ($server->dmap as $key => $val) {
            $outAr[] = new Value($key, 'string');
        }
        if ($server->allow_system_funcs) {
            foreach ($server->getSystemDispatchMap() as $key => $val) {
                $outAr[] = new Value($key, 'string');
            }
        }

        return new Response(new Value($outAr, 'array'));
    }

    public static function _xmlrpcs_methodSignature($server, $m)
    {
        // let accept as parameter both an xmlrpc value or string
        if (is_object($m)) {
            $methName = $m->getParam(0);
            $methName = $methName->scalarval();
        } else {
            $methName = $m;
        }
        if (strpos($methName, "system.") === 0) {
            $dmap = $server->getSystemDispatchMap();
        } else {
            $dmap = $server->dmap;
        }
        if (isset($dmap[$methName])) {
            if (isset($dmap[$methName]['signature'])) {
                $sigs = array();
                foreach ($dmap[$methName]['signature'] as $inSig) {
                    $cursig = array();
                    foreach ($inSig as $sig) {
                        $cursig[] = new Value($sig, 'string');
                    }
                    $sigs[] = new Value($cursig, 'array');
                }
                $r = new Response(new Value($sigs, 'array'));
            } else {
                // NB: according to the official docs, we should be returning a
                // "none-array" here, which means not-an-array
                $r = new Response(new Value('undef', 'string'));
            }
        } else {
            $r = new Response(0, PhpXmlRpc::$xmlrpcerr['introspect_unknown'], PhpXmlRpc::$xmlrpcstr['introspect_unknown']);
        }

        return $r;
    }

    public static function _xmlrpcs_methodHelp($server, $m)
    {
        // let accept as parameter both an xmlrpc value or string
        if (is_object($m)) {
            $methName = $m->getParam(0);
            $methName = $methName->scalarval();
        } else {
            $methName = $m;
        }
        if (strpos($methName, "system.") === 0) {
            $dmap = $server->getSystemDispatchMap();
        } else {
            $dmap = $server->dmap;
        }
        if (isset($dmap[$methName])) {
            if (isset($dmap[$methName]['docstring'])) {
                $r = new Response(new Value($dmap[$methName]['docstring']), 'string');
            } else {
                $r = new Response(new Value('', 'string'));
            }
        } else {
            $r = new Response(0, PhpXmlRpc::$xmlrpcerr['introspect_unknown'], PhpXmlRpc::$xmlrpcstr['introspect_unknown']);
        }

        return $r;
    }

    public static function _xmlrpcs_multicall_error($err)
    {
        if (is_string($err)) {
            $str = PhpXmlRpc::$xmlrpcstr["multicall_${err}"];
            $code = PhpXmlRpc::$xmlrpcerr["multicall_${err}"];
        } else {
            $code = $err->faultCode();
            $str = $err->faultString();
        }
        $struct = array();
        $struct['faultCode'] = new Value($code, 'int');
        $struct['faultString'] = new Value($str, 'string');

        return new Value($struct, 'struct');
    }

    public static function _xmlrpcs_multicall_do_call($server, $call)
    {
        if ($call->kindOf() != 'struct') {
            return static::_xmlrpcs_multicall_error('notstruct');
        }
        $methName = @$call->structmem('methodName');
        if (!$methName) {
            return static::_xmlrpcs_multicall_error('nomethod');
        }
        if ($methName->kindOf() != 'scalar' || $methName->scalartyp() != 'string') {
            return static::_xmlrpcs_multicall_error('notstring');
        }
        if ($methName->scalarval() == 'system.multicall') {
            return static::_xmlrpcs_multicall_error('recursion');
        }

        $params = @$call->structmem('params');
        if (!$params) {
            return static::_xmlrpcs_multicall_error('noparams');
        }
        if ($params->kindOf() != 'array') {
            return static::_xmlrpcs_multicall_error('notarray');
        }
        $numParams = $params->arraysize();

        $msg = new Request($methName->scalarval());
        for ($i = 0; $i < $numParams; $i++) {
            if (!$msg->addParam($params->arraymem($i))) {
                $i++;

                return static::_xmlrpcs_multicall_error(new Response(0,
                    PhpXmlRpc::$xmlrpcerr['incorrect_params'],
                    PhpXmlRpc::$xmlrpcstr['incorrect_params'] . ": probable xml error in param " . $i));
            }
        }

        $result = $server->execute($msg);

        if ($result->faultCode() != 0) {
            return static::_xmlrpcs_multicall_error($result); // Method returned fault.
        }

        return new Value(array($result->value()), 'array');
    }

    public static function _xmlrpcs_multicall_do_call_phpvals($server, $call)
    {
        if (!is_array($call)) {
            return static::_xmlrpcs_multicall_error('notstruct');
        }
        if (!array_key_exists('methodName', $call)) {
            return static::_xmlrpcs_multicall_error('nomethod');
        }
        if (!is_string($call['methodName'])) {
            return static::_xmlrpcs_multicall_error('notstring');
        }
        if ($call['methodName'] == 'system.multicall') {
            return static::_xmlrpcs_multicall_error('recursion');
        }
        if (!array_key_exists('params', $call)) {
            return static::_xmlrpcs_multicall_error('noparams');
        }
        if (!is_array($call['params'])) {
            return static::_xmlrpcs_multicall_error('notarray');
        }

        // this is a real dirty and simplistic hack, since we might have received a
        // base64 or datetime values, but they will be listed as strings here...
        $numParams = count($call['params']);
        $pt = array();
        foreach ($call['params'] as $val) {
            $pt[] = php_2_xmlrpc_type(gettype($val));
        }

        $result = $server->execute($call['methodName'], $call['params'], $pt);

        if ($result->faultCode() != 0) {
            return static::_xmlrpcs_multicall_error($result); // Method returned fault.
        }

        return new Value(array($result->value()), 'array');
    }

    public static function _xmlrpcs_multicall($server, $m)
    {
        $result = array();
        // let accept a plain list of php parameters, beside a single xmlrpc msg object
        if (is_object($m)) {
            $calls = $m->getParam(0);
            $numCalls = $calls->arraysize();
            for ($i = 0; $i < $numCalls; $i++) {
                $call = $calls->arraymem($i);
                $result[$i] = static::_xmlrpcs_multicall_do_call($server, $call);
            }
        } else {
            $numCalls = count($m);
            for ($i = 0; $i < $numCalls; $i++) {
                $result[$i] = static::_xmlrpcs_multicall_do_call_phpvals($server, $m[$i]);
            }
        }

        return new Response(new Value($result, 'array'));
    }

    /**
     * Error handler used to track errors that occur during server-side execution of PHP code.
     * This allows to report back to the client whether an internal error has occurred or not
     * using an xmlrpc response object, instead of letting the client deal with the html junk
     * that a PHP execution error on the server generally entails.
     *
     * NB: in fact a user defined error handler can only handle WARNING, NOTICE and USER_* errors.
     */
    public static function _xmlrpcs_errorHandler($errCode, $errString, $filename = null, $lineNo = null, $context = null)
    {
        // obey the @ protocol
        if (error_reporting() == 0) {
            return;
        }

        //if($errCode != E_NOTICE && $errCode != E_WARNING && $errCode != E_USER_NOTICE && $errCode != E_USER_WARNING)
        if ($errCode != E_STRICT) {
            \PhpXmlRpc\Server::error_occurred($errString);
        }
        // Try to avoid as much as possible disruption to the previous error handling
        // mechanism in place
        if ($GLOBALS['_xmlrpcs_prev_ehandler'] == '') {
            // The previous error handler was the default: all we should do is log error
            // to the default error log (if level high enough)
            if (ini_get('log_errors') && (intval(ini_get('error_reporting')) & $errCode)) {
                error_log($errString);
            }
        } else {
            // Pass control on to previous error handler, trying to avoid loops...
            if ($GLOBALS['_xmlrpcs_prev_ehandler'] != array('\PhpXmlRpc\Server', '_xmlrpcs_errorHandler')) {
                if (is_array($GLOBALS['_xmlrpcs_prev_ehandler'])) {
                    // the following works both with static class methods and plain object methods as error handler
                    call_user_func_array($GLOBALS['_xmlrpcs_prev_ehandler'], array($errCode, $errString, $filename, $lineNo, $context));
                } else {
                    $GLOBALS['_xmlrpcs_prev_ehandler']($errCode, $errString, $filename, $lineno, $context);
                }
            }
        }
    }
}
