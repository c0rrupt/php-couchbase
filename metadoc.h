/**
 *     Copyright 2016 Couchbase, Inc.
 *
 *   Licensed under the Apache License, Version 2.0 (the "License");
 *   you may not use this file except in compliance with the License.
 *   You may obtain a copy of the License at
 *
 *       http://www.apache.org/licenses/LICENSE-2.0
 *
 *   Unless required by applicable law or agreed to in writing, software
 *   distributed under the License is distributed on an "AS IS" BASIS,
 *   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *   See the License for the specific language governing permissions and
 *   limitations under the License.
 */

#ifndef METADOC_H_
#define METADOC_H_

#include <php.h>
#include <libcouchbase/couchbase.h>
#include "bucket.h"

void couchbase_init_metadoc(INIT_FUNC_ARGS);

int make_metadoc(zval *doc, zapval *value, zapval *flags, zapval *cas, zapval *token TSRMLS_DC);
int make_metadoc_error(zval *doc, lcb_error_t err TSRMLS_DC);

#endif // METADOC_H_
