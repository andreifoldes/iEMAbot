<?php

namespace backend\exceptions;

/**
 * The server could not keep an upload anywhere (the database refused it and so did the spool). Nothing was stored, so the upload
 * has to be answered with HTTP 503: the apps treat a 5xx as "try again later" and keep the data, unlike a normal error answer.
 */
class StorageUnavailableException extends CriticalException {
}
