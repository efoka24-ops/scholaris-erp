import { Module } from '@nestjs/common';
import { CateringController } from './catering.controller';
import { CateringService } from './catering.service';

@Module({
  controllers: [CateringController],
  providers: [CateringService],
  exports: [CateringService],
})
export class CateringModule {}
