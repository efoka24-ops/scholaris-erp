import { Module } from '@nestjs/common';
import { SchoolLifeController } from './school-life.controller';
import { SchoolLifeService } from './school-life.service';

@Module({
  controllers: [SchoolLifeController],
  providers: [SchoolLifeService],
  exports: [SchoolLifeService],
})
export class SchoolLifeModule {}
