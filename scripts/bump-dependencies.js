import Plugin  from 'release-it'

import fs from 'fs'
import util from 'util'
import { spawn } from 'child_process'

const readFile = util.promisify(fs.readFile);

const parse = async (data) => {
    return JSON.parse(data);
}

class UpdateDependencies extends Plugin {
    async init () {
        this.log.log("Getting current versions of OD dependencies")
        const composer = await readFile('./composer.json')
        const deps = await parse(composer)

        const core = deps.require["opendialogai/core"]
        await spawn(`./scripts/bump-core.sh`, [core], { stdio: 'inherit', stdout: 'inherit' })

        const webchat = deps.require["opendialogai/webchat"]
        await spawn(`./scripts/bump-webchat.sh`, [webchat], { stdio: 'inherit', stdout: 'inherit' })

        this.log.log("Getting current version of OD Design System")
        const packagejs = await readFile('./package.json')
        const nodeDeps = await parse(packagejs)

        const designSystem = nodeDeps.dependencies["@opendialogai/opendialog-design-system-pkg"];
        await spawn(`./scripts/bump-design-system.sh`, [designSystem], { stdio: 'inherit', stdout: 'inherit' })
    }
}

module.exports = UpdateDependencies
