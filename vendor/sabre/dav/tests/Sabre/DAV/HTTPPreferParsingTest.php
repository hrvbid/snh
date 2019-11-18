<?php

declare(strict_types=1);

namespace Sabre\DAV;

use Sabre\HTTP;

class HTTPPreferParsingTest extends \Sabre\DAVServerTest
{
    public function assertParseResult($input, $expected)
    {
        $httpRequest = new HTTP\Request('GET', '/foo', [
            'Prefer' => $input,
        ]);

        $server = new Server();
        $server->httpRequest = $httpRequest;

        $this->assertEquals(
            $expected,
            $server->getHTTPPrefer()
        );
    }

    public function testParseSimple()
    {
        $this->assertParseResult(
            'return-asynch',
            [
                'respond-async' => true,
                'return' => null,
                'handling' => null,
                'wait' => null,
            ]
        );
    }

    public function testParseValue()
    {
        $this->assertParseResult(
            'wait=10',
            [
                'respond-async' => false,
                'return' => null,
                'handling' => null,
                'wait' => '10',
            ]
        );
    }

    public function testParseMultiple()
    {
        $this->assertParseResult(
            'return-minimal, strict,lenient',
            [
                'respond-async' => false,
                'return' => 'minimal',
                'handling' => 'lenient',
                'wait' => null,
            ]
        );
    }

    public function testParseWeirdValue()
    {
        $this->assertParseResult(
            'BOOOH',
            [
                'respond-async' => false,
                'return' => null,
                'handling' => null,
                'wait' => null,
                'boooh' => true,
            ]
        );
    }

    public function testBrief()
    {
        $httpRequest = new HTTP\Request('GET', '/foo', [
            'Brief' => 't',
        ]);

        $server = new Server();
        $server->httpRequest = $httpRequest;

        $this->assertEquals([
            'respond-async' => false,
            'return' => 'minimal',
            'handling' => null,
            'wait' => null,
        ], $server->getHTTPPrefer());
    }

    /**
     * propfindMinimal.
     */
    public function testpropfindMinimal()
    {
        $request = new HTTP\Request('PROPFIND', '/', [
            'Prefer' => 'return-minimal',
        ]);
        $request->setBody(<<<BLA
<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:">
    <d:prop>
        <d:something />
        <d:resourcetype />
    </d:prop>
</d:propfind>
BLA
        );

        $response = $this->request($request);

        $body = $response->getBodyAsString();

        $this->assertEquals(207, $response->getStatus(), $body);

        $this->assertTrue(false !== strpos($body, 'resourcetype'), $body);
        $this->assertTrue(false === strpos($body, 'something'), $body);
    }

    public function testproppatchMinimal()
    {
        $request = new HTTP\Request('PROPPATCH', '/', ['Prefer' => 'return-minimal']);
        $request->setBody(<<<BLA
<?xml version="1.0"?>
<d:propertyupdate xmlns:d="DAV:">
    <d:set>
        <d:prop>
            <d:something>nope!</d:something>
        </d:prop>
    </d:set>
</d:propertyupdate>
BLA
        );

        $this->server->on('propPatch', function ($path, PropPatch $propPatch) {
            $propPatch->handle('{DAV:}something', function ($props) {
                return true;
            });
        });

        $response = $this->request($request);

        $this->assertEquals('', $response->getBodyAsString(), 'Expected empty body: '.$response->body);
        $this->assertEquals(204, $response->status);
    }

    public function testproppatchMinimalError()
    {
        $request = new HTTP\Request('PROPPATCH', '/', ['Prefer' => 'return-minimal']);
        $request->setBody(<<<BLA
<?xml version="1.0"?>
<d:propertyupdate xmlns:d="DAV:">
    <d:set>
        <d:prop>
            <d:something>nope!</d:something>
        </d:prop>
    </d:set>
</d:propertyupdate>
BLA
        );

        $response = $this->request($request);

        $body = $response->getBodyAsString();

        $this->assertEquals(207, $response->status);
        $this->assertTrue(false !== strpos($body, 'something'));
        $this->assertTrue(false !== strpos($body, '403 Forbidden'), $body);
    }
}
