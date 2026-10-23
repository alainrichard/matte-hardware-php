const fs=require('node:fs'),path=require('node:path');
if(!process.env.MATTE_TEST_ENV)throw new Error('Set MATTE_TEST_ENV to the isolated test .env file. These tests must not run against your migrated store.');
const env=require('dotenv').parse(fs.readFileSync(path.resolve(process.env.MATTE_TEST_ENV)));
if(!env.DB_NAME.endsWith('_test'))throw new Error('The test database name must end in _test.');
module.exports=env;
