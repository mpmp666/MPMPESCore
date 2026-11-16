#!/usr/bin/env python3
"""Run the user-supplied 26.2 EndCityPieces with minimal geometry-only Java stubs.

No Minecraft dependencies or network access are needed. The four generator
bodies, startHouseTower and recursiveChildren are copied unchanged out of the
selected source ZIP on each run. Templates' source bounding dimensions are
asserted by tools/test_end_city.php against the restored 26.2 resources.

Usage: python3 tools/end_city_reference.py /path/to/MCP-Reborn-26.2.zip > /tmp/end-city-reference.json
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import zipfile

SIZES = {
    'base_floor': [10, 4, 10], 'base_roof': [12, 2, 12],
    'bridge_end': [5, 6, 2], 'bridge_gentle_stairs': [5, 7, 8],
    'bridge_piece': [5, 6, 4], 'bridge_steep_stairs': [5, 7, 4],
    'fat_tower_base': [13, 4, 13], 'fat_tower_middle': [13, 8, 13],
    'fat_tower_top': [17, 6, 17], 'second_floor_1': [12, 8, 12],
    'second_floor_2': [12, 8, 12], 'second_roof': [14, 2, 14],
    'ship': [13, 24, 29], 'third_floor_1': [14, 8, 14],
    'third_floor_2': [14, 8, 14], 'third_roof': [16, 2, 16],
    'tower_base': [7, 7, 7], 'tower_floor': [7, 4, 7],
    'tower_piece': [7, 4, 7], 'tower_top': [9, 5, 9],
}
SEEDS = [0, 1, 2, 3, 5, 8, 13, 21, 42, 123456, -1, -42, 2147483647,
         -2147483648, 4294967296, 281474976710655, 9223372036854775807,
         -9223372036854775808, 244, 800, 838]
REGIONS = [[0, 0], [1, -1], [-5, 12], [100, -100], [-1, -1], [93750, -93750]]


def source_file(archive: zipfile.ZipFile, name: str) -> str:
    prefix = 'src/main/java/net/minecraft/'
    return archive.read(prefix + name).decode('utf-8')


def java_source(pieces: str) -> str:
    # Everything between these anchors is an unchanged source implementation.
    implementation = pieces[pieces.index('    private static final int MAX_GEN_DEPTH'):pieces.index('    public static class EndCityPiece')]
    interface = pieces[pieces.index('    private interface SectionGenerator'):].rsplit('\n}', 1)[0]
    declarations = '\n'.join('            case "' + k + '": return new int[]{' + ','.join(map(str, v)) + '};' for k, v in SIZES.items())
    support = r'''
import java.util.*;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
public class EndCityReference {
    record Pair<A,B>(A first, B second) {
        static <A,B> Pair<A,B> of(A a,B b){ return new Pair<>(a,b); }
        A getFirst(){ return first; } B getSecond(){ return second; }
    }
    record BlockPos(int x,int y,int z) {
        static final BlockPos ZERO = new BlockPos(0,0,0);
        int getX(){return x;} int getY(){return y;} int getZ(){return z;}
    }
    enum Rotation {
        NONE,CLOCKWISE_90,CLOCKWISE_180,COUNTERCLOCKWISE_90;
        Rotation getRotated(Rotation r){return values()[(ordinal()+r.ordinal())&3];}
        static Rotation getRandom(RandomSource r){return values()[r.nextInt(4)];}
    }
    static int[] rotate(int x,int z,Rotation r){
        return switch(r){case NONE->new int[]{x,z};case CLOCKWISE_90->new int[]{-z,x};case CLOCKWISE_180->new int[]{-x,-z};case COUNTERCLOCKWISE_90->new int[]{z,-x};};
    }
    static class RandomSource extends Random {RandomSource(long s){super(s);}}
    static class StructureTemplateManager{}
    static class StructurePlaceSettings {Rotation r;StructurePlaceSettings(Rotation r){this.r=r;}Rotation getRotation(){return r;}}
    static class StructureTemplate {
        BlockPos calculateConnectedPosition(StructurePlaceSettings a,BlockPos p,StructurePlaceSettings b,BlockPos q){
            int[] ap=rotate(p.x(),p.z(),a.r),bp=rotate(q.x(),q.z(),b.r);
            return new BlockPos(ap[0]-bp[0],p.y()-q.y(),ap[1]-bp[1]);
        }
    }
    static class BoundingBox {
        int[] b; BoundingBox(int[] b){this.b=b;}
        boolean intersects(BoundingBox o){int[] a=b,c=o.b;return a[3]>=c[0]&&a[0]<=c[3]&&a[4]>=c[1]&&a[1]<=c[4]&&a[5]>=c[2]&&a[2]<=c[5];}
    }
    static class StructurePiece {
        int genDepth=0;BoundingBox box;
        void setGenDepth(int d){genDepth=d;}int getGenDepth(){return genDepth;} BoundingBox getBoundingBox(){return box;}
        static StructurePiece findCollisionPiece(List<StructurePiece> p,BoundingBox b){for(StructurePiece a:p){if(a.box.intersects(b))return a;}return null;}
    }
    static int[] size(String name){switch(name){
SIZES
            default: throw new IllegalArgumentException(name);
    }}
    static class EndCityPieces {
    static class EndCityPiece extends StructurePiece {
        String name;BlockPos pos;Rotation r;boolean overwrite;
        EndCityPiece(StructureTemplateManager m,String n,BlockPos p,Rotation r,boolean o){name=n;pos=p;this.r=r;overwrite=o;update();}
        void update(){int[] s=size(name),d=rotate(s[0]-1,s[2]-1,r);box=new BoundingBox(new int[]{pos.x()+Math.min(0,d[0]),pos.y(),pos.z()+Math.min(0,d[1]),pos.x()+Math.max(0,d[0]),pos.y()+s[1]-1,pos.z()+Math.max(0,d[1])});}
        BlockPos templatePosition(){return pos;} StructureTemplate template(){return new StructureTemplate();}StructurePlaceSettings placeSettings(){return new StructurePlaceSettings(r);}
        void move(int x,int y,int z){pos=new BlockPos(pos.x()+x,pos.y()+y,pos.z()+z);update();}
    }
'''.replace('SIZES', declarations)
    tail = r'''
    }
    static String digest(List<StructurePiece> pieces) throws Exception {
        StringBuilder s=new StringBuilder();
        for(StructurePiece a:pieces){EndCityPieces.EndCityPiece p=(EndCityPieces.EndCityPiece)a;s.append("end_city/").append(p.name).append('|').append(p.pos.x()).append('|').append(p.pos.y()).append('|').append(p.pos.z()).append('|').append(p.r.ordinal()).append('|').append(p.overwrite?0:1).append('|').append(p.genDepth).append('\n');}
        return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(s.toString().getBytes(StandardCharsets.UTF_8)));
    }
    static int[] bounds(List<StructurePiece> pieces){
        int[] b={Integer.MAX_VALUE,Integer.MAX_VALUE,Integer.MAX_VALUE,Integer.MIN_VALUE,Integer.MIN_VALUE,Integer.MIN_VALUE};
        for(StructurePiece p:pieces){int[] a=p.box.b;for(int i=0;i<3;++i){b[i]=Math.min(b[i],a[i]);b[i+3]=Math.max(b[i+3],a[i+3]);}}return b;
    }
    public static void main(String[] args) throws Exception {
        StructureTemplateManager manager=new StructureTemplateManager();
        for(String arg:args){String[] parts=arg.split(",");long seed=Long.parseLong(parts[1]);RandomSource random=new RandomSource(seed);List<StructurePiece> p=new ArrayList<>();
            if(parts[0].equals("manual")){
                Rotation r=Rotation.getRandom(random);EndCityPieces.startHouseTower(manager,BlockPos.ZERO,r,p,random);
                System.out.println("manual|"+seed+"|"+r.ordinal()+"|"+p.size()+"|"+Arrays.toString(bounds(p))+"|"+digest(p)+"|"+random.nextInt());
            }else{
                int rx=Integer.parseInt(parts[2]),rz=Integer.parseInt(parts[3]);long placement=rx*341873128712L+rz*132897987541L+seed+10387313;
                RandomSource spread=new RandomSource(placement);int cx=rx*20+(spread.nextInt(9)+spread.nextInt(9))/2,cz=rz*20+(spread.nextInt(9)+spread.nextInt(9))/2;
                long xs=random.nextLong(),zs=random.nextLong();long source=cx*xs^cz*zs^seed;random.setSeed(source);Rotation r=Rotation.getRandom(random);
                EndCityPieces.startHouseTower(manager,BlockPos.ZERO,r,p,random);
                System.out.println("natural|"+seed+"|"+rx+"|"+rz+"|"+cx+"|"+cz+"|"+placement+"|"+source+"|"+r.ordinal()+"|"+p.size()+"|"+Arrays.toString(bounds(p))+"|"+digest(p)+"|"+random.nextInt());
            }
        }
    }
}
'''
    return support + implementation + interface + tail


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source_zip', type=Path)
    parser.add_argument('--scan', type=int, default=0, help='also check every manual seed from zero to N-1')
    args = parser.parse_args()
    with zipfile.ZipFile(args.source_zip) as archive:
        pieces = source_file(archive, 'world/level/levelgen/structure/structures/EndCityPieces.java')
    cases = [('manual', s) for s in sorted(set(SEEDS + list(range(args.scan))))]
    cases.extend(('natural', s, rx, rz) for s in SEEDS for rx, rz in REGIONS)
    with tempfile.TemporaryDirectory(prefix='end-city-reference-') as tmp:
        root = Path(tmp)
        source = root / 'EndCityReference.java'
        source.write_text(java_source(pieces))
        subprocess.run(['javac', str(source)], check=True, capture_output=True, text=True)
        # Keep command line lengths bounded even for a larger seed scan.
        rows = []
        for start in range(0, len(cases), 256):
            specs = [','.join(map(str, c)) for c in cases[start:start + 256]]
            result = subprocess.run(['java', '-cp', str(root), 'EndCityReference', *specs], check=True, capture_output=True, text=True)
            rows.extend(result.stdout.splitlines())
    results = []
    for line in rows:
        p = line.split('|')
        if p[0] == 'manual':
            results.append(dict(kind='manual', seed=int(p[1]), rotation=int(p[2]), count=int(p[3]), bounds=json.loads(p[4]), sha256=p[5], next_int=int(p[6])))
        else:
            results.append(dict(kind='natural', seed=int(p[1]), regionX=int(p[2]), regionZ=int(p[3]), chunkX=int(p[4]), chunkZ=int(p[5]), placement_seed=int(p[6]), source_seed=int(p[7]), rotation=int(p[8]), count=int(p[9]), bounds=json.loads(p[10]), sha256=p[11], next_int=int(p[12])))
    print(json.dumps(dict(source='Minecraft 26.2 supplied EndCityPieces.java', source_sha256=hashlib.sha256(pieces.encode('utf-8')).hexdigest(), sizes=SIZES, cases=results), separators=(',', ':')))


if __name__ == '__main__':
    main()
