"""Strict, dependency-free reader for Java Edition binary NBT (data only)."""
from __future__ import annotations

import gzip
import io
import struct


class NBTError(ValueError):
    pass


class Reader:
    def __init__(self, data: bytes):
        self.stream = io.BytesIO(data)
        self.length = len(data)

    def take(self, length: int) -> bytes:
        if length < 0 or length > self.length - self.stream.tell():
            raise NBTError(f"Truncated NBT at {self.stream.tell()}: requested {length} bytes")
        return self.stream.read(length)

    def number(self, fmt: str):
        return struct.unpack(">" + fmt, self.take(struct.calcsize(">" + fmt)))[0]

    def string(self) -> str:
        raw = self.take(self.number("H"))
        # Java DataInput uses modified UTF-8: NUL is C0 80 and supplementary
        # codepoints may be encoded as two UTF-16 surrogate code units.
        text = raw.replace(b"\xc0\x80", b"\x00").decode("utf-8", errors="surrogatepass")
        return text.encode("utf-16", errors="surrogatepass").decode("utf-16")

    def count(self) -> int:
        count = self.number("i")
        if count < 0 or count > self.length:
            raise NBTError(f"Invalid NBT collection size: {count}")
        return count

    def payload(self, kind: int, depth: int = 0):
        if depth > 128:
            raise NBTError("NBT nesting exceeds 128")
        if 1 <= kind <= 6:
            return self.number({1: "b", 2: "h", 3: "i", 4: "q", 5: "f", 6: "d"}[kind])
        if kind == 7:
            return [value if value < 128 else value - 256 for value in self.take(self.count())]
        if kind == 8:
            return self.string()
        if kind == 9:
            child, count = self.number("B"), self.count()
            if child == 0 and count:
                raise NBTError("Nonempty TAG_End list")
            return [self.payload(child, depth + 1) for _ in range(count)]
        if kind == 10:
            result = {}
            while True:
                child = self.number("B")
                if child == 0:
                    return result
                name = self.string()
                if name in result:
                    raise NBTError(f"Duplicate compound key: {name!r}")
                result[name] = self.payload(child, depth + 1)
        if kind in (11, 12):
            count = self.count()
            fmt = "i" if kind == 11 else "q"
            raw = self.take(count * struct.calcsize(">" + fmt))
            return list(struct.unpack(">" + str(count) + fmt, raw))
        raise NBTError(f"Unsupported NBT tag type {kind}")


def loads(data: bytes) -> dict:
    if data.startswith(b"\x1f\x8b"):
        data = gzip.decompress(data)
    reader = Reader(data)
    kind = reader.number("B")
    if kind != 10:
        raise NBTError(f"Expected compound root, got {kind}")
    reader.string()
    result = reader.payload(kind)
    if reader.stream.tell() != len(data):
        raise NBTError("Trailing bytes after NBT root")
    return result
