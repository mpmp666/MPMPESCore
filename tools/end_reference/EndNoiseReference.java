import java.nio.ByteBuffer;
import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.List;

/** Executable test shell; algorithm classes are generated from the supplied MCP zip. */
public final class EndNoiseReference {
    static final long[] SEEDS = {0L, 1L, -1L, 123456789L, -987654321L, Long.MIN_VALUE, Long.MAX_VALUE};
    static final int[][] POINTS = {
        {0,0,0},{0,4,0},{0,8,0},{0,32,0},{0,56,0},{0,60,0},{0,64,0},{0,68,0},{0,72,0},{0,128,0},
        {8,48,-8},{-8,48,8},{-1,48,-1},{-7,32,-15},{-9,59,17},{64,64,64},{-80,56,-80},
        {128,60,-128},{512,56,0},{1024,64,0},{1032,64,-1032},{1280,64,1280},
        {-1440,48,-1440},{2064,60,-1200},{-4096,72,8000},{12344,56,-54328},
        {30000000,64,-29999992},{-30000000,56,29999992},{367000,64,0},{524288,64,-524288}
    };
    static String number(double x) {
        return Double.isNaN(x) ? "\"NaN\"" : Double.isInfinite(x) ? "\""+x+"\"" : Double.toString(x);
    }
    static String hash(byte[] bytes) throws Exception {
        return java.util.HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(bytes));
    }
    static double gradient(int y,int from,int to,double a,double b){
        return Mth.clampedLerp((y-(double)from)/(to-(double)from),a,b);
    }
    static final class Terrain {
        final BlendedNoise noise;
        final EndIslands islands;
        Terrain(long seed){noise=new BlendedNoise(new LegacyRandomSource(seed));islands=new EndIslands(seed);}
        double island(int x,int z){return islands.compute(new Context(x,0,z));}
        double height(int x,int z){return island(x,z)*128.0+8.0;}
        double raw(int x,int y,int z){
            double bottom=gradient(y,4,32,0.0,1.0);
            if(bottom==0.0) return 0.64 * -0.234375;
            double top=gradient(y,56,312,1.0,0.0);
            double cave=island(x,z)+noise.compute(new Context(x,y,z));
            double upper = top==0.0 ? 0.0 : top*(23.4375+cave);
            return 0.64 * (-0.234375 + bottom*(0.234375+(-23.4375+upper)));
        }
        double[] grid(int cx,int cz){
            double[] n=new double[297];int i=0;
            for(int gx=0;gx<=2;gx++)for(int gz=0;gz<=2;gz++)for(int gy=0;gy<=32;gy++)
                n[i++]=raw(cx*16+gx*8,gy*4,cz*16+gz*8);
            return n;
        }
    }
    static void separator(StringBuilder out,boolean first){if(!first)out.append(',');}
    public static void main(String[] args)throws Exception{
        StringBuilder out=new StringBuilder("{\"random\":[");boolean first=true;
        for(long seed:SEEDS){
            separator(out,first);first=false;
            LegacyRandomSource r=new LegacyRandomSource(seed);
            out.append("{\"seed\":\"").append(seed).append("\",\"draws\":[");boolean drawFirst=true;
            for(int i=0;i<24;i++){
                separator(out,drawFirst);drawFirst=false;
                out.append("[\"int\",").append(r.nextInt()).append(']');
            }
            for(int bound:new int[]{1,2,3,7,16,255,256,257,1073741825,2147483647})for(int i=0;i<8;i++){
                out.append(", [\"bounded\",").append(bound).append(',').append(r.nextInt(bound)).append(']');
            }
            for(int i=0;i<8;i++)out.append(", [\"long\",\"").append(r.nextLong()).append("\"]");
            for(int i=0;i<8;i++)out.append(", [\"double\",").append(number(r.nextDouble())).append(']');
            for(int i=0;i<8;i++)out.append(", [\"float\",").append(number(r.nextFloat())).append(']');
            for(int i=0;i<8;i++)out.append(", [\"boolean\",").append(r.nextBoolean()).append(']');
            r.setSeed(seed);r.consumeCount(17292);
            out.append("],\"consumed\":[").append(r.nextInt()).append(',').append(number(r.nextDouble())).append("]}");
        }
        out.append("],\"long_arithmetic\":[");first=true;
        for(long a:new long[]{0,1,-1,Long.MIN_VALUE,Long.MAX_VALUE,0x1122334455667788L})
            for(long b:new long[]{1,-1,Long.MIN_VALUE,Long.MAX_VALUE,341873128712L,-123456789L}){
                separator(out,first);first=false;
                out.append("[\"").append(a).append("\",\"").append(b).append("\",\"").append(a+b).append("\",\"").append(a*b).append("\"]");
            }
        out.append("],\"worldgen\":[");first=true;
        for(long seed:SEEDS)for(int[] pos:new int[][]{{0,0},{1,-1},{-12345,67890},{1874999,-1875000}}){
            separator(out,first);first=false;LegacyRandomSource r=new LegacyRandomSource(seed);
            out.append("{\"seed\":\"").append(seed).append("\",\"x\":").append(pos[0]).append(",\"z\":").append(pos[1]);
            r.setLargeFeatureSeed(seed,pos[0],pos[1]);
            out.append(",\"large\":[").append(r.nextInt(10387313)).append(",\"").append(r.nextLong()).append("\"]");
            r.setLargeFeatureWithSalt(seed,pos[0],pos[1],10387313);
            out.append(",\"salt\":[").append(r.nextInt(10387313)).append(",\"").append(r.nextLong()).append("\"]");
            long decoration=r.setDecorationSeed(seed,pos[0],pos[1]);
            out.append(",\"decoration\":[\"").append(decoration).append("\",").append(r.nextInt()).append(']');
            r.setFeatureSeed(decoration,17,4);
            out.append(",\"feature\":[").append(r.nextInt()).append(",\"").append(r.nextLong()).append("\"]}");
        }
        out.append("],\"feature_seed_edges\":[");first=true;
        for(long seed:SEEDS)for(int[] pair:new int[][]{{Integer.MAX_VALUE,1},{Integer.MIN_VALUE,-1},{17,214749},{-99,Integer.MAX_VALUE},{0,Integer.MIN_VALUE}}){
            separator(out,first);first=false;LegacyRandomSource r=new LegacyRandomSource(seed);r.setFeatureSeed(seed,pair[0],pair[1]);
            out.append("[\"").append(seed).append("\",").append(pair[0]).append(',').append(pair[1]).append(',').append(r.nextInt()).append(",\"").append(r.nextLong()).append("\"]");
        }
        out.append("],\"noise\":[");first=true;
        for(long seed:SEEDS){
            separator(out,first);first=false;
            Terrain t=new Terrain(seed);
            LegacyRandomSource r=new LegacyRandomSource(seed);r.consumeCount(17292);SimplexNoise simplex=new SimplexNoise(r);
            ImprovedNoise improved=new ImprovedNoise(new LegacyRandomSource(seed));
            out.append("{\"seed\":\"").append(seed).append("\",\"simplex\":[");boolean sf=true;
            for(double[] p:new double[][]{{0,0},{1,-1},{-0.125,0.75},{80,80},{-80,-80},{1000,-9000},{1875000,-1875000}}){
                separator(out,sf);sf=false;out.append('[').append(number(p[0])).append(',').append(number(p[1])).append(',').append(number(simplex.getValue(p[0],p[1]))).append(']');
            }
            out.append("],\"improved\":[");sf=true;
            for(double[] p:new double[][]{{0,0,0,0,0},{1.25,-3.5,7.125,0,0},{-99.5,64,80,684.412,64},{123456,32,-789012,0.125,-4},{-0.001,0.1,-0.002,0.01,0.001}}){
                separator(out,sf);sf=false;out.append('[');
                for(double v:p)out.append(number(v)).append(',');
                out.append(number(improved.noise(p[0],p[1],p[2],p[3],p[4]))).append(']');
            }
            out.append("],\"points\":[");sf=true;
            for(int[] p:POINTS){
                separator(out,sf);sf=false;
                out.append('[').append(p[0]).append(',').append(p[1]).append(',').append(p[2]).append(',')
                   .append(number(t.height(p[0],p[2]))).append(',')
                   .append(number(t.noise.compute(new Context(p[0],p[1],p[2])))).append(',')
                   .append(number(t.raw(p[0],p[1],p[2]))).append(']');
            }
            out.append("],\"biomes\":[");sf=true;
            for(int[] p:new int[][]{{0,0},{1023,0},{1024,0},{1040,0},{-1024,0},{-1025,0},{1280,1280},{-1440,-1440},{2064,-1200},{30000000,-29999992}}){
                separator(out,sf);sf=false;int cx=p[0]>>4,cz=p[1]>>4;String biome;
                if((long)cx*cx+(long)cz*cz<=4096L)biome="the_end";
                else{double v=t.island((cx*2+1)*8,(cz*2+1)*8);biome=v>0.25?"end_highlands":v>=-0.0625?"end_midlands":v< -0.21875?"small_end_islands":"end_barrens";}
                out.append('[').append(p[0]).append(',').append(p[1]).append(",\"").append(biome).append("\"]");
            }
            out.append("],\"chunks\":[");sf=true;
            List<int[]> chunks=new ArrayList<>(List.of(new int[]{0,0},new int[]{-1,-1},new int[]{80,80},new int[]{-90,-90},new int[]{129,-75}));
            findIsland: for(int cx=65;cx<100;cx++)for(int cz=-100;cz<100;cz++){
                if(t.island(cx*16+8,cz*16+8)>0.25){chunks.add(new int[]{cx,cz});break findIsland;}
            }
            for(int[] chunk:chunks){
                separator(out,sf);sf=false;int cx=chunk[0],cz=chunk[1];double[] nodes=t.grid(cx,cz);
                ByteBuffer lattice=ByteBuffer.allocate(nodes.length*8);for(double n:nodes)lattice.putDouble(n);
                byte[] mask=new byte[32768];int mi=0;Interpolation ip=new Interpolation();
                int[] heights=new int[256];
                for(int x=0;x<16;x++)for(int z=0;z<16;z++)for(int y=0;y<128;y++){
                    double d=ip.compute(nodes,x>>3,y>>2,z>>3,(x&7)/8.0,(y&3)/4.0,(z&7)/8.0);
                    boolean solid=d>0.0;mask[mi++]=(byte)(solid?1:0);if(solid)heights[x*16+z]=y+1;
                }
                out.append("{\"x\":").append(cx).append(",\"z\":").append(cz)
                   .append(",\"lattice_sha256\":\"").append(hash(lattice.array())).append("\",\"solid_sha256\":\"").append(hash(mask)).append("\",\"heights\":[");
                for(int i=0;i<heights.length;i++){if(i>0)out.append(',');out.append(heights[i]);}
                out.append("],\"samples\":[");boolean bf=true;
                for(int[] p:new int[][]{{0,32,0},{1,47,15},{7,61,3},{8,64,8},{15,73,15},{6,127,11}}){
                    separator(out,bf);bf=false;
                    double d=ip.compute(nodes,p[0]>>3,p[1]>>2,p[2]>>3,(p[0]&7)/8.0,(p[1]&3)/4.0,(p[2]&7)/8.0);
                    double c=Mth.clamp(d,-1,1),squeeze=c/2.0-c*c*c/24.0;
                    out.append('[').append(p[0]).append(',').append(p[1]).append(',').append(p[2]).append(',').append(number(d)).append(',').append(number(squeeze)).append(']');
                }
                out.append("]}");
            }
            out.append("]}");
        }
        out.append("]}");System.out.println(out);
    }
}
