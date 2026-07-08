"""WS-Gateway — асинхронный сервис-транспорт для универсального pull-API воркеров.

Единственный читатель job-стримов `conv.<type>` (KeyDB Streams, группа
`convertor`, db2) и владелец `XREADGROUP`/`XACK`/`XAUTOCLAIM`. На шаге s1-02 —
только фундамент: конфиг + KeyDB-слой. WS-сервер и dispatch — s1-03+.
"""
