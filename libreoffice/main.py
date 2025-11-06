
from aiohttp import web
import os
import signal
import subprocess
import io
import sys
import tempfile
import re
import json

#https://pythonexamples.org/run.php

async def doc2docxOld_handle(request):
    return await baseHandleOld(request, 'docx', 'docx')

async def doc2textOld_handle(request):
    return await baseHandleOld(request, 'txt:Text (encoded):UTF8', 'txt')


async def baseHandleOld(request, convertTo, ext):
    with tempfile.NamedTemporaryFile(delete=False) as output:
        reader = await request.multipart()
        docx = await reader.next()

        while True:
            chunk = await docx.read_chunk()
            if not chunk:
                break
            output.write(chunk)

        try:
            output.close()
            os.chmod(output.name, 0o666)

            #print ("Input file: ", os.path.abspath(output.name))
            proc = subprocess.Popen(
                'soffice --headless --convert-to "'+convertTo+'" --outdir "' +
                os.path.abspath(os.path.dirname(output.name)) + '" "' +
                os.path.abspath(output.name) + '"'
            , shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
            ## > /dev/null
            ##print ("Command: ", proc.args)

            code = proc.wait(120)
            ##print ("Exec code: ", code)
            outs, errs = proc.communicate(timeout=120)
            print (outs , file=sys.stderr)
            if errs:
                raise Exception(errs)

            path = "{}." + ext
            path = path.format(output.name)
            #print ("Output file: ", path)

            response = web.StreamResponse(
                status=200,
                reason="OK",
            )
            response.content_type = 'text/plain'
            await response.prepare(request)

            with io.open(path, mode='rb') as f:
                ##response.content_length = len(data)
                await response.write(f.read())

            await response.write_eof()

        except Exception as e:
            print("Exception: ", e, file=sys.stderr)
            response = web.Response(
                body=str(e),
                status=400,
                reason="Bad request",
                content_type='text/text',
            )

        os.unlink(path)
        os.unlink(output.name)

        return response


async def doc2docx_handle(request):
    return await baseHandle(request, 'docx', 'docx')

async def doc2text_handle(request):
    return await baseHandle(request, 'txt:Text (encoded):UTF8', 'txt')


async def baseHandle(request, convertTo, ext):
    data = await request.post()
    code = 1
    outs = ''
    errs = ''
    try:
        #print ("Input file: ", data['file'], file=sys.stderr)
        if not os.path.isfile(data['file']):
            raise Exception('File not available')
        ### TODO: check ext (doc,docx,rtf,odt,pdf)
        ## match = re.search(r'\.([a-z]{3,4})$', r'test.txt')
        ## print(match[0] if match else 'Not found')

        proc = subprocess.Popen(
            'soffice --headless --convert-to "' + convertTo + '" --outdir "' +
            os.path.abspath(os.path.dirname(data['file'])) + '" "' +
            os.path.abspath(data['file']) + '"'
        , shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)

        print ("Command: ", proc.args, file=sys.stderr)

        try:
            #code = proc.wait(120)
            #print ("Exec code: ", code, file=sys.stderr)
            outs, errs = proc.communicate(timeout=120)
            #print ("Outs: ", outs , file=sys.stderr)
        except Exception as e:
            ##proc.kill()
            os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
            raise e

        path = re.sub(r'([\w\d]+\.)([a-z]{3,4})$', r'\1'+ext, data['file'])
        os.chmod(path, 0o666)

        response = web.Response(
            body=json.dumps({'code': code, 'outs': outs, 'error': errs}).encode('utf-8'),
            status=200,
            reason="OK",
            content_type='application/json',
        )

    except Exception as e:
        print("Exception: ", e, file=sys.stderr)
        response = web.Response(
            body=json.dumps({'code': code, 'outs': outs, 'error': errs, 'exception': str(e)}).encode('utf-8'),
            status=400,
            reason="Bad request",
            content_type='application/json',
        )

    return response

if __name__ == '__main__':
    app = web.Application()
    app.router.add_post('/doc2textOld', doc2textOld_handle)
    app.router.add_post('/doc2docxOld', doc2docxOld_handle)
    app.router.add_post('/doc2text', doc2text_handle)
    app.router.add_post('/doc2docx', doc2docx_handle)

    web.run_app(app, port=int(os.getenv('PORT', "80")))
